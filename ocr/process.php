<?php
session_start();
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'patient') {
    header("Location:../auth/login.php");
    exit();
}
error_reporting(E_ALL);
ini_set('display_errors', 1);

$conn = new mysqli("localhost", "root", "", "SHAPMS");
if ($conn->connect_error) die("DB Error: " . $conn->connect_error);

$text       = null;
$error      = null;
$summary    = null;
$reportType = 'LAB';

// ════════════════════════════════════════════════════
// STEP 1 — Classify report type
// ════════════════════════════════════════════════════
function classifyReport($raw) {
    $radiologySignals = [
        '/\bCT\b|\bCT\s+scan\b/i',
        '/\bMRI\b|\bMR\s+scan\b/i',
        '/\bULTRASOUND\b|\bUSG\b|\bSONOGRAPHY\b/i',
        '/\bX-RAY\b|\bRADIOGRAPH\b/i',
        '/\bPET\s+scan\b|\bNUCLEAR\b/i',
        '/\bFINDINGS\s*:/i',
        '/\bIMPRESSION\s*:/i',
        '/\bRADIOLOGY\b|\bRADIOLOGIST\b/i',
        '/\bcm\s*[x×]\s*\d|mm\s*[x×]\s*\d/i',
        '/\blesion\b|\bnodule\b|\bmass\b|\btumou?r\b/i',
        '/\bparenchyma\b|\bmediastin|\bcortex\b/i',
        '/\bechotexture\b|\bechogenicity\b/i',
        '/\battenuation\b|\bHounsfield\b/i',
    ];
    foreach ($radiologySignals as $pat) {
        if (preg_match($pat, $raw)) return 'RADIOLOGY';
    }
    $labLineCount = 0;
    foreach (explode("\n", $raw) as $line) {
        $line = trim($line);
        if (strlen($line) < 5) continue;
        if (!preg_match('/\d+\.?\d*/', $line)) continue;
        $hasUnit  = preg_match('/mg\/dl|g\/dl|mmol|meq|iu\/|u\/l|ng\/|pg\/|%|\/ul|\/mm|mg\/l|mmhg/i', $line);
        $hasRange = preg_match('/\d+\s*[-–]\s*\d+|<\s*\d+|>\s*\d+|negative|positive/i', $line);
        if ($hasUnit || $hasRange) $labLineCount++;
    }
    if ($labLineCount >= 2) return 'LAB';
    $generalSignals = [
        '/\bMEDICAL\s+REPORT\b/i', '/\bCONSULTATION\b/i',
        '/\bK\/C\/O\b/i', '/\bC\/O\b/i', '/\bReview\s+Notes\b/i',
        '/\bSlit\s*Lamp\b/i', '/\bExamination\b/i', '/\bDiagnosis\b/i',
        '/\bAdvice\b/i', '/\bFollow\s*-?\s*up\b/i', '/\bChief\s+Complaint/i',
        '/\bVision\b|\bDVA\b|\bVA\b\s*[:\-]/i', '/\bDate\s+of\s+Exam/i',
        '/MR\s*No\.?\s*\/\s*UHID/i',
    ];
    foreach ($generalSignals as $pat) {
        if (preg_match($pat, $raw)) return 'GENERAL';
    }
    return 'LAB';
}

// ════════════════════════════════════════════════════
// STEP 2 — Preprocess functions
// ════════════════════════════════════════════════════
function preprocessRadiologyOCR($raw) {
    $sectionPatterns = [
        'CLINICAL HISTORY' => '/(?:CLINICAL\s+HISTORY|CLINICAL\s+INDICATION|INDICATION)[:\s]*(.*?)(?=\n[A-Z][A-Z\s]+:|$)/si',
        'TECHNIQUE'        => '/(?:TECHNIQUE|PROTOCOL)[:\s]*(.*?)(?=\n[A-Z][A-Z\s]+:|$)/si',
        'FINDINGS'         => '/(?:FINDINGS?|OBSERVATIONS?)[:\s]*(.*?)(?=\n[A-Z][A-Z\s]+:|$)/si',
        'IMPRESSION'       => '/(?:IMPRESSION|CONCLUSION|DIAGNOSIS)[:\s]*(.*?)(?=\n[A-Z][A-Z\s]+:|$)/si',
        'RECOMMENDATION'   => '/(?:RECOMMENDATION|ADVICE|SUGGESTED)[:\s]*(.*?)(?=\n[A-Z][A-Z\s]+:|$)/si',
    ];
    $output = '';
    foreach ($sectionPatterns as $label => $pat) {
        if (preg_match($pat, $raw, $m)) {
            $content = trim($m[1]);
            if (strlen($content) > 5) $output .= "$label:\n$content\n";
        }
    }
    if (empty($output)) {
        $lines = explode("\n", $raw);
        $kept  = [];
        foreach ($lines as $line) {
            $line = trim($line);
            if (strlen($line) < 4) continue;
            if (preg_match('/^[A-Z\s\.\,\-]{3,40}$/', $line) && !preg_match('/\d/', $line)) continue;
            $kept[] = $line;
        }
        $output = implode("\n", $kept);
    }
    return $output;
}

function preprocessGeneralOCR($raw) {
    $lines = explode("\n", $raw);
    $kept  = [];
    $skip  = [
        '/hospital|campus|diagnostic|centre|clinic|address|phone|email|www\.|http/i',
        '/^\s*(mr\s*no|uh\s*id|mrno|contact\s+no|review\s+no)[\.\s]*[:\/]/i',
        '/^\s*(location|district|telangana|address)\s*[:\-]?\s*$/i',
        '/patient\s+id|sample\s+type|barcode/i',
        '/^\s*\d{6,}\s*\/\s*\d+\s*$/',
        '/^\s*\d{10}\s*$/',
        '/^[A-Z\s\.\,\-]{3,60}$/u',
        '/road\s+no|banjara|hills|maheshwaram|rangareddy|rangareddi|kandukur/i',
    ];
    foreach ($lines as $line) {
        $line = trim($line);
        if (strlen($line) < 3) continue;
        $s = false;
        foreach ($skip as $p) { if (preg_match($p, $line)) { $s = true; break; } }
        if (!$s) $kept[] = $line;
    }
    $output = implode("\n", $kept);
    return trim($output) !== '' ? $output : $raw;
}

// ════════════════════════════════════════════════════
// STEP 3 — Gemini calls
// ════════════════════════════════════════════════════
function getLabFindings($cleanLabText, $interpretationText) {
    $url = "https://generativelanguage.googleapis.com/v1beta/models/gemini-2.0-flash:generateContent?key=AIzaSyDi7BoRat9kqIPJO9fedgFWmURnpE3V4wc";
    $prompt = 'You are analyzing OCR text from a medical lab report. The text may be messy with extra symbols like ·, @, Ф, O, C, 0 mixed into the data — ignore those symbols.

Extract EVERY test result you can find. For each one:
- test: the test name (e.g. "Haemoglobin", "WBC Count")
- result: just the numeric value with unit (e.g. "12.4 g/dL", "9010 /mm3")
- range: the reference range (e.g. "14.0-18.0", "4000-11000")
- flag: compare result to range and set NORMAL, HIGH, or LOW

Numbers with commas like 9,010 = 9010. Numbers like 147,000 = 147000.

OCR TEXT:
' . $cleanLabText . '

INTERPRETATION SECTION (for diagnosis only):
' . $interpretationText . '

Return ONLY valid JSON (no markdown, no code fences):
{
  "report_type": "LAB",
  "condition_status": "CRITICAL or SERIOUS or STABLE",
  "all_results": [
    {"test": "name", "result": "value unit", "range": "low-high", "flag": "NORMAL or HIGH or LOW or ABNORMAL"}
  ],
  "abnormal_labs": [],
  "critical_findings": [],
  "diagnosis": ["max 3 from interpretation"],
  "urgent_actions": ["max 3 actions"]
}';
    return callGemini($url, $prompt);
}

function getRadiologyFindings($cleanRadText) {
    $url = "https://generativelanguage.googleapis.com/v1beta/models/gemini-2.0-flash:generateContent?key=AIzaSyDi7BoRat9kqIPJO9fedgFWmURnpE3V4wc";
    $prompt = 'You are a radiologist assistant analyzing an imaging report (CT/MRI/Ultrasound/X-Ray).

REPORT TEXT:
' . $cleanRadText . '

Return ONLY valid JSON (no markdown, no code fences):
{
  "report_type": "RADIOLOGY",
  "condition_status": "CRITICAL or SERIOUS or STABLE",
  "all_results": [],
  "abnormal_labs": [],
  "critical_findings": ["concise finding, include measurements"],
  "diagnosis": ["primary diagnosis — max 3"],
  "urgent_actions": ["clinical action — max 3"]
}';
    return callGemini($url, $prompt);
}

function getGeneralFindings($cleanGeneralText) {
    $url = "https://generativelanguage.googleapis.com/v1beta/models/gemini-2.0-flash:generateContent?key=AIzaSyDi7BoRat9kqIPJO9fedgFWmURnpE3V4wc";
    $prompt = 'You are a clinical assistant analyzing a consultation/examination report.

REPORT TEXT:
' . $cleanGeneralText . '

Return ONLY valid JSON (no markdown, no code fences):
{
  "report_type": "GENERAL",
  "condition_status": "CRITICAL or SERIOUS or STABLE",
  "all_results": [
    {"test": "name", "result": "value unit", "range": "normal range or empty", "flag": "NORMAL or HIGH or LOW or ABNORMAL"}
  ],
  "abnormal_labs": [],
  "critical_findings": ["key clinical findings"],
  "diagnosis": ["diagnosis — max 3"],
  "urgent_actions": ["follow-up actions — max 3"]
}';
    return callGemini($url, $prompt);
}

// ════════════════════════════════════════════════════
// Shared Gemini HTTP call + FLAG RECALCULATION
// ════════════════════════════════════════════════════
function callGemini($url, $prompt) {
    $payload = json_encode([
        "contents"         => [["parts" => [["text" => $prompt]]]],
        "generationConfig" => ["temperature" => 0.0, "maxOutputTokens" => 4000]
    ]);
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ["Content-Type: application/json"]);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    $res  = curl_exec($ch);
    $data = json_decode($res, true);
    $raw  = $data['candidates'][0]['content']['parts'][0]['text'] ?? null;
    if (!$raw) return null;
    $raw     = preg_replace('/^```(?:json)?\s*/m', '', $raw);
    $raw     = preg_replace('/```\s*$/m', '', $raw);
    $summary = json_decode(trim($raw), true);
    if (!$summary) return null;

    // ── Recalculate flags numerically ──
    if (!empty($summary['all_results']) && is_array($summary['all_results'])) {
        foreach ($summary['all_results'] as &$row) {
            $resultRaw = trim($row['result'] ?? '');
            $range     = trim($row['range']  ?? '');
            if (empty($range)) continue;
            if (preg_match('/negative|positive|nil|absent|present|trace|reactive/i', $resultRaw)) continue;
            $resultClean = str_replace(',', '', $resultRaw);
            $rangeClean  = str_replace(',', '', $range);
            if (!preg_match('/(\d+\.?\d*)/', $resultClean, $vm)) continue;
            $val = (float)$vm[1];
            $rangeClean = preg_replace('/\s*[–—−\-]\s*/', '-', $rangeClean);
            if (preg_match('/^(\d+\.?\d*)-(\d+\.?\d*)$/', $rangeClean, $m)) {
                $lo = (float)$m[1]; $hi = (float)$m[2];
                if      ($val < $lo) $row['flag'] = 'LOW';
                elseif  ($val > $hi) $row['flag'] = 'HIGH';
                else                 $row['flag'] = 'NORMAL';
            } elseif (preg_match('/<\s*(\d+\.?\d*)/', $rangeClean, $m)) {
                $row['flag'] = ($val >= (float)$m[1]) ? 'HIGH' : 'NORMAL';
            } elseif (preg_match('/>\s*(\d+\.?\d*)/', $rangeClean, $m)) {
                $row['flag'] = ($val <= (float)$m[1]) ? 'LOW' : 'NORMAL';
            } elseif (preg_match('/up\s*to\s*(\d+\.?\d*)/i', $rangeClean, $m)) {
                $row['flag'] = ($val > (float)$m[1]) ? 'HIGH' : 'NORMAL';
            }
        }
        unset($row);
        $summary['abnormal_labs'] = array_values(array_filter(
            $summary['all_results'],
            fn($r) => in_array(strtoupper($r['flag'] ?? ''), ['HIGH', 'LOW', 'ABNORMAL'])
        ));
    }
    return $summary;
}

// ════════════════════════════════════════════════════
// Helpers
// ════════════════════════════════════════════════════
function extractInterpretation($raw) {
    if (preg_match('/(?:INTERPRETATION|CLINICAL CORRELATION)[:\s]*(.*?)(?:THEORY|GENERAL ADVICE|Note:|---)/si', $raw, $m))
        return trim($m[1]);
    return '';
}

function extractPatientInfo($raw) {
    $info = ['name' => '', 'age_gender' => '', 'referred_by' => '', 'doctor' => ''];
    if (preg_match('/Patient\s+Name\s*[:\|]\s*([^\n\t]+)/i', $raw, $m))
        $info['name'] = trim(preg_replace('/\s+/', ' ', $m[1]));
    if (preg_match('/Age\s*\/\s*Gender\s*[:\|]\s*([^\n\t]+)/i', $raw, $m))
        $info['age_gender'] = trim(preg_replace('/\s+/', ' ', $m[1]));
    if (preg_match('/Referred\s+By\s*[:\|]\s*([^\n\t]+)/i', $raw, $m))
        $info['referred_by'] = trim(preg_replace('/\s+/', ' ', $m[1]));
    if (preg_match('/Dr\.\s+([A-Z][a-z]+(?:\s+[A-Z][a-z]+)*)\s*\n/m', $raw, $m))
        $info['doctor'] = 'Dr. ' . trim($m[1]);
    if (empty($info['name'])) {
        if (preg_match('/^\s*(?:Mr|Mrs|Ms|Master|Miss)\.?\s+([A-Za-z][A-Za-z\.\s]{1,40})\s*$/mi', $raw, $m))
            $info['name'] = trim(preg_replace('/\s+/', ' ', $m[0]));
    }
    return $info;
}

// ════════════════════════════════════════════════════
// Build OCR highlighted panel — LAB
// ════════════════════════════════════════════════════
function buildHighlightedLabOCR($rawText, $summary) {
    $testFlagMap = [];
    if (!empty($summary['all_results'])) {
        foreach ($summary['all_results'] as $lab) {
            $name = strtolower(trim($lab['test'] ?? ''));
            $flag = strtoupper(trim($lab['flag'] ?? 'NORMAL'));
            if (!empty($name)) $testFlagMap[$name] = $flag;
        }
    }
    $skipPatterns = [
        '/hospital|diagnostic|centre|clinic|address|phone|email|www\.|http/i',
        '/patient\s+name|age\s*\/|referred\s+by|sample\s+type|patient\s+id/i',
        '/collection\s+date|report\s+date|lab\s+no|barcode/i',
        '/theory|information|advice|note:|end\s+of\s+report/i',
        '/disclaimer/i',
        '/normochromic|normocytic|platelets\s+are\s+adequate/i',
        '/kindly\s+correlate|medico.legal/i',
        '/dr\.\s+|md\s*\(|consultant|pathologist/i',
    ];
    $lines  = explode("\n", $rawText);
    $output = '';
    foreach ($lines as $line) {
        $line = trim($line);
        if (strlen($line) < 5) continue;
        if (!preg_match('/\d+\.?\d*/', $line)) continue;
        $s = false;
        foreach ($skipPatterns as $p) { if (preg_match($p, $line)) { $s = true; break; } }
        if ($s) continue;
        $hasUnit  = preg_match('/mg\/dl|g\/dl|mmol|meq|iu\/|u\/l|ng\/|pg\/|%|\/ul|\/mm|mg\/l|mmhg|mil\/mm|\/mm3/i', $line);
        $hasRange = preg_match('/\d+\s*[-–]\s*\d+|<\s*\d+|>\s*\d+/i', $line);
        if (!$hasUnit && !$hasRange) continue;
        $lineLower   = strtolower($line);
        $matchedFlag = null;
        foreach ($testFlagMap as $testName => $flag) {
            $words    = preg_split('/\s+/', $testName);
            $allFound = true;
            foreach ($words as $w) {
                if (strlen($w) > 2 && strpos($lineLower, $w) === false) { $allFound = false; break; }
            }
            if ($allFound) { $matchedFlag = $flag; break; }
        }
        $escaped = htmlspecialchars($line);
        if ($matchedFlag === null) {
            $output .= '<div class="hl-row hl-rad-normal">' . $escaped . '</div>';
        } elseif ($matchedFlag === 'HIGH') {
            $output .= '<div class="hl-row hl-abnormal">▲ ' . $escaped . '</div>';
        } elseif ($matchedFlag === 'LOW') {
            $output .= '<div class="hl-row hl-low">▼ ' . $escaped . '</div>';
        } elseif ($matchedFlag === 'ABNORMAL') {
            $output .= '<div class="hl-row hl-abnormal">⚠ ' . $escaped . '</div>';
        } else {
            $output .= '<div class="hl-row hl-normal">✓ ' . $escaped . '</div>';
        }
    }
    return $output ?: '<div class="hl-empty">No result lines detected.</div>';
}

// ════════════════════════════════════════════════════
// Build OCR highlighted panel — RADIOLOGY
// ════════════════════════════════════════════════════
function buildHighlightedRadiologyOCR($rawText, $summary) {
    $criticalPhrases = [];
    if (!empty($summary['critical_findings'])) {
        foreach ($summary['critical_findings'] as $cf) {
            foreach (preg_split('/\s+/', strtolower(trim($cf))) as $w)
                if (strlen($w) > 5) $criticalPhrases[] = $w;
        }
    }
    $sectionHeaders = [
        'CLINICAL HISTORY','CLINICAL INDICATION','INDICATION','TECHNIQUE','PROTOCOL',
        'FINDINGS','OBSERVATIONS','IMPRESSION','CONCLUSION','RECOMMENDATION','ADVICE',
    ];
    $footerPatterns = [
        '/end\s+of\s+report/i', '/\*{3,}/', '/^\s*disclaimer\s*$/i',
        '/not\s+valid\s+for\s+medico.legal/i', '/\bkmc\s+no\s*[:\-]/i', '/reg\.\s*no\s*[:\-]/i',
    ];
    $inlineSkip = [
        '/^\s*[•\*]+\s*$/', '/\bhospital\b|\bdiagnostic\s+cent|\bclinic\b/i',
        '/^\s*ph\s*[:\-]\s*\d/i', '/^\s*fax\s*[:\-]/i', '/www\.|https?:\/\//i',
        '/patient\s+(name|id|no)\s*[:\|]/i', '/age\s*[\/\\\\]\s*gender/i',
        '/referred\s+by\s*[:\|]/i', '/study\s+date|report\s+date|study\s+type|collection\s+date/i',
        '/barcode|lab\s+no\b|sample\s+type/i',
    ];
    $lines = explode("\n", $rawText);
    $output = ''; $inSection = false; $done = false;
    foreach ($lines as $line) {
        $line = trim($line);
        if ($done) break;
        if (strlen($line) < 3) { if ($inSection) $output .= '<div class="hl-spacer"></div>'; continue; }
        foreach ($footerPatterns as $fp) { if (preg_match($fp, $line)) { $done = true; break; } }
        if ($done) break;
        $isHeader = false;
        foreach ($sectionHeaders as $h) {
            if (preg_match('/' . preg_quote($h, '/') . '\s*[:\-]?\s*/i', $line)) {
                $stripped = trim(preg_replace('/' . preg_quote($h, '/') . '/i', '', $line), " :\-\t");
                if (strlen($stripped) < 60) {
                    $inSection = true; $isHeader = true;
                    $output .= '<div class="hl-section-hd">' . htmlspecialchars($line) . '</div>';
                    break;
                }
            }
        }
        if ($isHeader) continue;
        if (!$inSection) continue;
        $skip = false;
        foreach ($inlineSkip as $sp) { if (preg_match($sp, $line)) { $skip = true; break; } }
        if ($skip) continue;
        $lineLower = strtolower($line); $isCritical = false;
        foreach ($criticalPhrases as $phrase) { if (strpos($lineLower, $phrase) !== false) { $isCritical = true; break; } }
        if (preg_match('/\d+\.?\d*\s*[x×]\s*\d+\.?\d*\s*(?:cm|mm)/i', $line)) $isCritical = true;
        if (preg_match('/lesion|lymphaden|metastati|neoplasti|effusion|emphysem|nodule|\bmass\b|tumou?r|obstruct|infarct|hemorrhage|fracture|collaps|opacity|consolidat|calcif|atrophy|stenosis|dilatat|rupture|abscess/i', $line)) $isCritical = true;
        if (preg_match('/^\s*\d+\.\s+[A-Z]/', $line)) $isCritical = true;
        $escaped = htmlspecialchars($line);
        $output .= $isCritical
            ? '<div class="hl-row hl-abnormal">⚠ ' . $escaped . '</div>'
            : '<div class="hl-row hl-rad-normal">' . $escaped . '</div>';
    }
    if (!$output) {
        foreach ($lines as $line) {
            $line = trim($line);
            if (strlen($line) < 8) continue;
            $lineLower = strtolower($line); $isCritical = false;
            foreach ($criticalPhrases as $phrase) { if (strpos($lineLower, $phrase) !== false) { $isCritical = true; break; } }
            if (preg_match('/\d+\.?\d*\s*[x×]\s*\d+\.?\d*\s*(?:cm|mm)/i', $line)) $isCritical = true;
            if (preg_match('/lesion|lymphaden|metastati|neoplasti|effusion|emphysem|nodule|\bmass\b|tumou?r|obstruct|infarct|hemorrhage|fracture|collaps|opacity|consolidat|calcif|atrophy|stenosis/i', $line)) $isCritical = true;
            $escaped = htmlspecialchars($line);
            $output .= $isCritical
                ? '<div class="hl-row hl-abnormal">⚠ ' . $escaped . '</div>'
                : '<div class="hl-row hl-rad-normal">' . $escaped . '</div>';
        }
    }
    return $output ?: '<div class="hl-empty">No report content could be extracted.</div>';
}

// ════════════════════════════════════════════════════
// Build OCR highlighted panel — GENERAL
// ════════════════════════════════════════════════════
function buildHighlightedGeneralOCR($rawText, $summary) {
    $criticalPhrases = [];
    foreach (['critical_findings', 'diagnosis'] as $key) {
        if (!empty($summary[$key])) {
            foreach ($summary[$key] as $cf) {
                foreach (preg_split('/\s+/', strtolower(trim($cf))) as $w) {
                    $w = trim($w, ".,-");
                    if (strlen($w) > 4) $criticalPhrases[] = $w;
                }
            }
        }
    }
    $skipPatterns = [
        '/hospital|campus|diagnostic|centre|clinic|address|phone|email|www\.|http/i',
        '/^\s*(mr\s*no|uh\s*id|mrno|contact\s+no|review\s+no)[\.\s]*[:\/]/i',
        '/patient\s+id|sample\s+type|barcode/i',
        '/^\s*\d{6,}\s*\/\s*\d+\s*$/', '/^\s*\d{10}\s*$/',
        '/road\s+no|banjara|hills|maheshwaram|rangareddy/i',
        '/^\s*\*{2,}\s*$/', '/end\s+of\s+report/i', '/disclaimer/i',
    ];
    $lines = explode("\n", $rawText);
    $output = '';
    foreach ($lines as $line) {
        $line = trim($line);
        if (strlen($line) < 3) { $output .= '<div class="hl-spacer"></div>'; continue; }
        $s = false;
        foreach ($skipPatterns as $p) { if (preg_match($p, $line)) { $s = true; break; } }
        if ($s) continue;
        $isHeader  = (bool)preg_match('/^[A-Za-z][A-Za-z\s\/\-]{2,40}:?\s*$/', $line)
                     && strlen($line) < 45
                     && preg_match('/[:]$|^[A-Z][a-z]+(\s[A-Z][a-z]+)*$/', $line);
        $lineLower = strtolower($line); $isCritical = false;
        foreach ($criticalPhrases as $phrase) { if (strpos($lineLower, $phrase) !== false) { $isCritical = true; break; } }
        $escaped = htmlspecialchars($line);
        if ($isHeader)        $output .= '<div class="hl-section-hd">' . $escaped . '</div>';
        elseif ($isCritical)  $output .= '<div class="hl-row hl-abnormal">⚠ ' . $escaped . '</div>';
        else                  $output .= '<div class="hl-row hl-rad-normal">' . $escaped . '</div>';
    }
    return $output ?: '<div class="hl-empty">No report content could be extracted.</div>';
}

// ════════════════════════════════════════════════════
// MAIN
// ════════════════════════════════════════════════════
$wordCount      = 0;
$charCount      = 0;
$patientInfo    = [];
$highlightedOCR = '';
$patient_id     = (int)$_SESSION['user_id'];

if (isset($_FILES['image']) && $_FILES['image']['error'] === UPLOAD_ERR_OK) {
    $tmp       = $_FILES['image']['tmp_name'];
    $fileName  = time() . "_" . basename($_FILES['image']['name']);
    $uploadDir = "../uploads/";
    if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);
    $imgPath = $uploadDir . $fileName;
    move_uploaded_file($tmp, $imgPath);

    $b64 = 'data:image/png;base64,' . base64_encode(file_get_contents($imgPath));

    // First OCR pass to classify
    $ch = curl_init('https://api.ocr.space/parse/image');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => ['apikey'=>'K85510009388957','base64Image'=>$b64,'language'=>'eng','OCREngine'=>'2','isTable'=>'false'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 60,
    ]);
    $ocrResp = json_decode(curl_exec($ch), true);
    $text    = $ocrResp['ParsedResults'][0]['ParsedText'] ?? null;

    if ($text) {
        $reportType = classifyReport($text);

        // For LAB re-run with isTable=true
        if ($reportType === 'LAB') {
            $ch2 = curl_init('https://api.ocr.space/parse/image');
            curl_setopt_array($ch2, [
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => ['apikey'=>'K85510009388957','base64Image'=>$b64,'language'=>'eng','OCREngine'=>'2','isTable'=>'true'],
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => 60,
            ]);
            $ocrResp2 = json_decode(curl_exec($ch2), true);
            $labText  = $ocrResp2['ParsedResults'][0]['ParsedText'] ?? null;
            if ($labText) $text = $labText;
        }

        $wordCount   = str_word_count($text);
        $charCount   = strlen($text);
        $patientInfo = extractPatientInfo($text);

       if ($reportType === 'LAB') {
            $interpretation = extractInterpretation($text);
            $summary        = getLabFindings($text, $interpretation);

            // TEMP DEBUG — remove after checking
            error_log('GEMINI SUMMARY: ' . print_r($summary, true));
            if (!$summary) {
                error_log('GEMINI RETURNED NULL — raw text length: ' . strlen($text));
            }

            $highlightedOCR = buildHighlightedLabOCR($text, $summary ?? []);
        } elseif ($reportType === 'RADIOLOGY') {
            $cleanContent   = preprocessRadiologyOCR($text);
            $summary        = getRadiologyFindings($cleanContent);
            $highlightedOCR = buildHighlightedRadiologyOCR($text, $summary ?? []);
        } else {
            $cleanContent   = preprocessGeneralOCR($text);
            $summary        = getGeneralFindings($cleanContent);
            $highlightedOCR = buildHighlightedGeneralOCR($text, $summary ?? []);
        }

        $summaryJson = $summary ? json_encode($summary) : null;
        $stmt = $conn->prepare("INSERT INTO ocr_scans (patient_id, image_path, extracted_text, ai_summary, word_count, char_count, scanned_at) VALUES (?,?,?,?,?,?,NOW())");
        $stmt->bind_param("isssii", $patient_id, $imgPath, $text, $summaryJson, $wordCount, $charCount);
        $stmt->execute();
        $stmt->close();
    } else {
        $error = 'Nothing detected. Try a clearer image.';
    }
}

$history = [];
$res = $conn->query("SELECT * FROM ocr_scans WHERE patient_id = $patient_id ORDER BY scanned_at DESC LIMIT 10");
if ($res) while ($row = $res->fetch_assoc()) $history[] = $row;
?>


<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>OCR Scanner — Zaman Medical</title>
<link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700;800&family=DM+Mono:wght@400;500&display=swap" rel="stylesheet">
<style>
:root{
  --bg:#f5f2fc;--surface:#ffffff;--surface2:#f8f6fd;--surface3:#efe9fb;
  --border:#e6def8;--border2:#d9cdf4;
  --violet:#8b5cf6;--violet2:#7c3aed;--violet-pale:rgba(139,92,246,.10);--violet-glow:rgba(139,92,246,.18);
  --cyan:#0891b2;--cyan2:#0e7490;--pink:#ec4899;--pink2:#db2777;
  --t0:#2e1f47;--t1:#5b4a7a;--t2:#8576a3;--t3:#aea0c9;
  --red:#dc2626;--red-bg:#fef1f2;--red-bd:#fecdd3;
  --orange:#ea580c;--orange-bg:#fff7ed;--orange-bd:#fed7aa;
  --green:#16a34a;--green-bg:#f0fdf4;--green-bd:#bbf7d0;
  --blue-bg:rgba(37,99,235,.06);--blue-bd:rgba(37,99,235,.22);--blue:#2563eb;
  --r:12px;--r-lg:18px;
  --sh:0 2px 20px rgba(139,92,246,.08);--sh-lg:0 6px 32px rgba(139,92,246,.12);
}
*,*::before,*::after{margin:0;padding:0;box-sizing:border-box;}
body{font-family:'Outfit',sans-serif;background:var(--bg);color:var(--t0);min-height:100vh;}
body::before{content:'';position:fixed;inset:0;z-index:0;pointer-events:none;
  background:radial-gradient(ellipse 700px 500px at 10% 0%,rgba(139,92,246,.06),transparent 60%),
             radial-gradient(ellipse 500px 500px at 90% 90%,rgba(8,145,178,.04),transparent 55%);}

/* TOPBAR */
.topbar{background:#fff;padding:0 32px;display:flex;align-items:center;min-height:62px;border-bottom:1px solid var(--border);position:sticky;top:0;z-index:100;}
.topbar::after{content:'';position:absolute;bottom:0;left:0;right:0;height:2px;background:linear-gradient(90deg,var(--violet),var(--cyan),var(--violet));}
.tb-logo{width:36px;height:36px;border-radius:9px;background:var(--violet-pale);border:1px solid var(--border2);display:grid;place-items:center;font-size:17px;margin-right:12px;}
.tb-name{font-size:.92rem;font-weight:800;color:var(--t0);}
.tb-sub{font-size:.62rem;color:var(--t3);}
.tb-div{width:1px;height:26px;background:var(--border);margin:0 20px;}
.tb-pill{font-size:.6rem;font-weight:800;letter-spacing:.1em;text-transform:uppercase;padding:4px 12px;border-radius:20px;background:rgba(8,145,178,.08);border:1px solid rgba(8,145,178,.22);color:var(--cyan2);display:flex;align-items:center;gap:6px;}
.tb-dot{width:5px;height:5px;border-radius:50%;background:var(--cyan2);animation:blink 2s infinite;}
@keyframes blink{0%,100%{opacity:1}50%{opacity:.2}}
.tb-av{margin-left:auto;width:32px;height:32px;border-radius:8px;background:linear-gradient(135deg,var(--violet),var(--cyan));display:grid;place-items:center;font-size:.7rem;font-weight:800;color:#fff;}

/* PAGE */
.page{position:relative;z-index:1;max-width:860px;margin:0 auto;padding:36px 20px 80px;}
.hdr{margin-bottom:28px;animation:up .5s both;}
.badge{display:inline-flex;align-items:center;gap:6px;background:var(--violet-pale);border:1px solid var(--border2);border-radius:100px;padding:4px 12px;font-size:.62rem;font-weight:800;letter-spacing:.1em;text-transform:uppercase;color:var(--violet2);margin-bottom:12px;}
h1{font-size:clamp(22px,3.5vw,34px);font-weight:800;color:var(--t0);margin-bottom:6px;line-height:1.15;}
h1 em{font-style:normal;background:linear-gradient(120deg,var(--violet2),var(--cyan2));-webkit-background-clip:text;-webkit-text-fill-color:transparent;background-clip:text;}
.hdr-sub{font-size:.82rem;color:var(--t2);line-height:1.6;}

/* CARDS */
.card{background:var(--surface);border:1px solid var(--border);border-radius:var(--r-lg);box-shadow:var(--sh);padding:26px;position:relative;overflow:hidden;margin-bottom:18px;animation:up .5s .05s both;}
.card::before{content:'';position:absolute;top:0;left:0;right:0;height:2px;background:linear-gradient(90deg,var(--violet),var(--cyan));}
.card-lbl{font-size:.65rem;font-weight:800;letter-spacing:.09em;text-transform:uppercase;color:var(--t2);margin-bottom:14px;display:flex;align-items:center;gap:6px;}
.card-lbl::before{content:'';width:14px;height:2px;background:linear-gradient(90deg,var(--violet),var(--cyan));border-radius:2px;}

/* UPLOAD */
.drop{border:2px dashed var(--border2);border-radius:var(--r);padding:40px 20px;text-align:center;cursor:pointer;background:var(--surface2);transition:all .2s;position:relative;overflow:hidden;}
.drop:hover,.drop.over{border-color:var(--violet);background:var(--surface3);box-shadow:0 0 0 4px var(--violet-glow);}
.drop input{position:absolute;inset:0;opacity:0;cursor:pointer;width:100%;height:100%;}
.drop-ico{width:60px;height:60px;border-radius:15px;background:var(--violet-pale);border:1px solid var(--border2);display:flex;align-items:center;justify-content:center;font-size:26px;margin:0 auto 14px;transition:transform .2s;}
.drop:hover .drop-ico{transform:translateY(-3px);}
.drop-title{font-size:.95rem;font-weight:700;color:var(--t0);margin-bottom:4px;}
.drop-sub{font-size:.74rem;color:var(--t3);}
.fmts{display:flex;justify-content:center;gap:5px;margin-top:12px;}
.fmt{font-size:.58rem;font-weight:800;padding:2px 8px;border-radius:5px;background:var(--violet-pale);color:var(--violet2);border:1px solid var(--border2);text-transform:uppercase;}
#prev{display:none;margin-top:16px;text-align:center;}
#prev img{max-height:180px;border-radius:var(--r);border:1px solid var(--border2);object-fit:contain;box-shadow:var(--sh-lg);}
.prev-info{display:inline-flex;gap:5px;margin-top:8px;font-size:.66rem;color:var(--t2);background:var(--surface3);border:1px solid var(--border);border-radius:6px;padding:3px 10px;font-family:'DM Mono',monospace;}

/* BUTTON */
.btn{margin-top:18px;width:100%;padding:14px;background:linear-gradient(135deg,#7c3aed,var(--violet),var(--cyan));border:none;border-radius:var(--r);color:#fff;font-size:.88rem;font-weight:800;font-family:'Outfit',sans-serif;cursor:pointer;transition:all .2s;box-shadow:0 4px 24px var(--violet-glow);display:flex;align-items:center;justify-content:center;gap:8px;}
.btn:hover{transform:translateY(-2px);box-shadow:0 8px 36px rgba(139,92,246,.30);}

/* SCANNING */
.scanning{display:none;align-items:center;justify-content:center;gap:10px;padding:13px;color:var(--violet2);font-size:.8rem;font-weight:700;background:var(--surface3);border:1px solid var(--border2);border-radius:var(--r);margin-top:14px;}
.spin{width:16px;height:16px;border:2px solid var(--border2);border-top-color:var(--violet);border-radius:50%;animation:spin .75s linear infinite;}
@keyframes spin{to{transform:rotate(360deg)}}
.scan-bar{width:100px;height:3px;border-radius:3px;background:var(--border);overflow:hidden;}
.scan-fill{height:100%;background:linear-gradient(90deg,var(--violet),var(--cyan));animation:slide 1.5s ease-in-out infinite;}
@keyframes slide{0%{width:0;margin-left:0}50%{width:60%;margin-left:20%}100%{width:0;margin-left:100%}}

/* ERROR */
.err{margin-bottom:18px;padding:14px 18px;border-radius:var(--r-lg);background:var(--red-bg);border:1px solid var(--red-bd);border-left:4px solid var(--red);color:var(--red);font-size:.83rem;font-weight:600;}

/* BANNER */
.banner{margin-bottom:18px;padding:16px 20px;border-radius:var(--r-lg);display:flex;align-items:center;gap:14px;animation:up .4s both;}
.banner.CRITICAL{background:var(--red-bg);border:1px solid var(--red-bd);border-left:4px solid var(--red);}
.banner.SERIOUS{background:var(--orange-bg);border:1px solid var(--orange-bd);border-left:4px solid var(--orange);}
.banner.STABLE{background:var(--green-bg);border:1px solid var(--green-bd);border-left:4px solid var(--green);}
.banner-ico{width:42px;height:42px;border-radius:11px;display:grid;place-items:center;font-size:20px;flex-shrink:0;}
.banner-title{font-size:.95rem;font-weight:800;}
.banner.CRITICAL .banner-title{color:var(--red);}
.banner.SERIOUS  .banner-title{color:var(--orange);}
.banner.STABLE   .banner-title{color:var(--green);}
.banner-sub{font-size:.72rem;color:var(--t2);margin-top:2px;}

/* REPORT TYPE BADGE */
.rtype-badge{display:inline-flex;align-items:center;gap:6px;padding:4px 12px;border-radius:7px;font-size:.65rem;font-weight:800;letter-spacing:.07em;text-transform:uppercase;}
.rtype-badge.LAB{background:rgba(8,145,178,.08);color:var(--cyan2);border:1px solid rgba(8,145,178,.22);}
.rtype-badge.RADIOLOGY{background:var(--violet-pale);color:var(--violet2);border:1px solid var(--border2);}
.rtype-badge.GENERAL{background:rgba(236,72,153,.08);color:var(--pink2);border:1px solid rgba(236,72,153,.22);}

/* RESULT CARD */
.result-card{background:var(--surface);border:1px solid var(--border);border-radius:var(--r-lg);overflow:hidden;box-shadow:var(--sh-lg);margin-bottom:18px;animation:up .45s both;}
.rc-head{padding:16px 22px;border-bottom:1px solid var(--border);background:linear-gradient(90deg,var(--surface3),var(--surface));display:flex;align-items:center;gap:10px;flex-wrap:wrap;}
.rc-icon{width:36px;height:36px;border-radius:9px;background:linear-gradient(135deg,var(--violet),var(--cyan));display:grid;place-items:center;font-size:16px;flex-shrink:0;}
.rc-title{font-size:.82rem;font-weight:800;color:var(--t0);}
.rc-sub{font-size:.63rem;color:var(--t2);margin-top:1px;}
.rc-body{padding:20px 22px;display:flex;flex-direction:column;gap:18px;}

/* PATIENT INFO */
.pt-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(130px,1fr));gap:8px;}
.pt-box{background:var(--surface2);border:1px solid var(--border);border-radius:9px;padding:9px 12px;}
.pt-lbl{font-size:.56rem;font-weight:800;text-transform:uppercase;letter-spacing:.08em;color:var(--t3);margin-bottom:3px;}
.pt-val{font-size:.8rem;font-weight:700;color:var(--t1);}

/* SECTION LABEL */
.sec-lbl{font-size:.6rem;font-weight:800;text-transform:uppercase;letter-spacing:.1em;color:var(--t3);margin-bottom:10px;display:flex;align-items:center;gap:6px;}
.sec-lbl::before{content:'';width:10px;height:2px;background:linear-gradient(90deg,var(--violet),var(--cyan));border-radius:2px;}

/* RESULTS TABLE */
.labs-tbl{width:100%;border-collapse:collapse;}
.labs-tbl th{padding:9px 14px;font-size:.6rem;font-weight:800;text-transform:uppercase;letter-spacing:.07em;color:var(--t3);text-align:left;border-bottom:1px solid var(--border2);background:var(--surface3);}
.labs-tbl td{padding:11px 14px;font-size:.8rem;border-bottom:1px solid var(--border);vertical-align:middle;}
.labs-tbl tr:last-child td{border-bottom:none;}
.labs-tbl tr.row-normal td{background:var(--green-bg);}
.labs-tbl tr.row-normal:hover td{background:var(--green-bg);filter:brightness(.97);}
.labs-tbl tr.row-high td{background:var(--red-bg);}
.labs-tbl tr.row-high:hover td{background:var(--red-bg);filter:brightness(.97);}
.labs-tbl tr.row-low td{background:var(--blue-bg);}
.labs-tbl tr.row-low:hover td{background:var(--blue-bg);filter:brightness(.97);}
.labs-tbl tr.row-abnormal td{background:var(--orange-bg);}
.labs-tbl tr.row-abnormal:hover td{background:var(--orange-bg);filter:brightness(.97);}
.t-name{font-weight:700;color:var(--t1);}
.t-range{font-size:.68rem;color:var(--t3);font-family:'DM Mono',monospace;}
.flag{display:inline-flex;align-items:center;gap:3px;padding:3px 9px;border-radius:5px;font-size:.6rem;font-weight:800;letter-spacing:.05em;}
.flag.H{background:var(--red-bg);color:var(--red);border:1px solid var(--red-bd);}
.flag.L{background:var(--blue-bg);color:var(--blue);border:1px solid var(--blue-bd);}
.flag.A{background:var(--orange-bg);color:var(--orange);border:1px solid var(--orange-bd);}
.flag.N{background:var(--green-bg);color:var(--green);border:1px solid var(--green-bd);}

/* TAGS */
.tags{display:flex;flex-wrap:wrap;gap:7px;}
.tag{display:inline-flex;align-items:center;gap:5px;padding:6px 11px;border-radius:8px;font-size:.7rem;font-weight:700;border:1px solid;}
.tag.dx{background:var(--red-bg);color:var(--red);border-color:var(--red-bd);}
.tag.rad{background:var(--violet-pale);color:var(--violet2);border-color:var(--border2);}

/* FINDINGS */
.findings-list{display:flex;flex-direction:column;gap:7px;}
.finding-item{display:flex;align-items:flex-start;gap:10px;background:var(--red-bg);border:1px solid var(--red-bd);border-radius:9px;padding:10px 14px;}
.finding-dot{width:8px;height:8px;border-radius:50%;background:var(--red);flex-shrink:0;margin-top:4px;}
.finding-txt{font-size:.78rem;font-weight:600;color:var(--red);line-height:1.5;}

/* ACTIONS */
.act-list{display:flex;flex-direction:column;gap:8px;}
.act-item{display:flex;align-items:flex-start;gap:10px;background:var(--green-bg);border:1px solid var(--green-bd);border-radius:9px;padding:10px 14px;}
.act-num{width:22px;height:22px;border-radius:6px;background:linear-gradient(135deg,#16a34a,var(--cyan));display:grid;place-items:center;font-size:.62rem;font-weight:800;color:#fff;flex-shrink:0;}
.act-txt{font-size:.77rem;font-weight:600;color:var(--green);line-height:1.5;}
.all-normal{text-align:center;padding:28px;background:var(--green-bg);border:1px solid var(--green-bd);border-radius:var(--r);color:var(--green);font-weight:700;font-size:.85rem;}

/* LEGEND */
.res-legend{display:flex;gap:14px;margin-bottom:12px;flex-wrap:wrap;}
.res-legend-item{display:flex;align-items:center;gap:5px;font-size:.65rem;font-weight:700;color:var(--t3);}
.res-dot{width:10px;height:10px;border-radius:3px;}
.res-dot.norm{background:var(--green-bg);border:1px solid var(--green-bd);}
.res-dot.high{background:var(--red-bg);border:1px solid var(--red-bd);}
.res-dot.low{background:var(--blue-bg);border:1px solid var(--blue-bd);}
.res-dot.abn{background:var(--orange-bg);border:1px solid var(--orange-bd);}

/* RAW OCR PANEL */
.raw-card{background:var(--surface);border:1px solid var(--border);border-radius:var(--r-lg);overflow:hidden;box-shadow:var(--sh);margin-bottom:18px;}
.raw-hd{padding:14px 20px;border-bottom:1px solid var(--border);display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:8px;background:linear-gradient(90deg,var(--surface3),var(--surface));cursor:pointer;user-select:none;}
.raw-hl{display:flex;align-items:center;gap:10px;}
.raw-icon{width:34px;height:34px;border-radius:8px;background:linear-gradient(135deg,#7c3aed,var(--violet));display:grid;place-items:center;font-size:14px;}
.raw-stats{display:flex;gap:7px;flex-wrap:wrap;align-items:center;}
.stat{display:flex;align-items:center;gap:4px;background:var(--surface3);border:1px solid var(--border);border-radius:7px;padding:4px 10px;font-size:.68rem;font-weight:700;color:var(--t2);}
.sv-badge{display:inline-flex;gap:5px;align-items:center;background:var(--green-bg);border:1px solid var(--green-bd);color:var(--green);border-radius:7px;padding:4px 10px;font-size:.68rem;font-weight:700;}
.raw-toggle{display:inline-flex;align-items:center;gap:5px;background:var(--violet-pale);border:1px solid var(--border2);color:var(--violet2);border-radius:7px;padding:4px 10px;font-size:.68rem;font-weight:800;}
.raw-toggle svg{transition:transform .2s;}
.raw-toggle.open svg{transform:rotate(180deg);}
.raw-body{padding:18px 20px;display:none;}
.raw-body.open{display:block;}
.hl-legend{display:flex;gap:14px;margin-bottom:12px;flex-wrap:wrap;}
.hl-legend-item{display:flex;align-items:center;gap:5px;font-size:.65rem;font-weight:700;color:var(--t3);}
.hl-dot{width:10px;height:10px;border-radius:3px;}
.hl-dot.abn{background:var(--red-bg);border:1px solid var(--red-bd);}
.hl-dot.norm{background:var(--green-bg);border:1px solid var(--green-bd);}
.hl-dot.low{background:var(--blue-bg);border:1px solid var(--blue-bd);}
.hl-dot.rad{background:var(--surface3);border:1px solid var(--border2);}
.hl-results{display:flex;flex-direction:column;gap:5px;max-height:360px;overflow-y:auto;}
.hl-row{padding:8px 14px;border-radius:8px;font-family:'DM Mono',monospace;font-size:.76rem;line-height:1.5;border-left:3px solid transparent;}
.hl-abnormal{background:var(--red-bg);border-left-color:var(--red);color:var(--red);font-weight:700;}
.hl-low{background:var(--blue-bg);border-left-color:var(--blue);color:var(--blue);font-weight:700;}
.hl-normal{background:var(--green-bg);border-left-color:var(--green);color:var(--green);font-weight:500;}
.hl-rad-normal{background:var(--surface2);border-left-color:var(--border2);color:var(--t2);}
.hl-section-hd{padding:6px 14px;font-size:.62rem;font-weight:800;text-transform:uppercase;letter-spacing:.08em;color:var(--violet2);background:var(--surface3);border-radius:6px;border-left:3px solid var(--violet);margin-top:8px;}
.hl-spacer{height:4px;}
.hl-empty{color:var(--t3);font-size:.75rem;padding:12px;}

/* EMPTY */
.empty{background:var(--surface);border:1px solid var(--border);border-radius:var(--r-lg);padding:50px 28px;text-align:center;box-shadow:var(--sh);margin-bottom:18px;}
.empty-ico{width:64px;height:64px;border-radius:16px;margin:0 auto 18px;background:var(--surface3);border:1px solid var(--border);display:flex;align-items:center;justify-content:center;font-size:28px;}
.empty h3{font-size:.95rem;font-weight:700;color:var(--t1);margin-bottom:5px;}
.empty p{font-size:.76rem;color:var(--t3);max-width:280px;margin:0 auto;line-height:1.6;}

/* HISTORY */
.hist-card{background:var(--surface);border:1px solid var(--border);border-radius:var(--r-lg);overflow:hidden;box-shadow:var(--sh);}
.hist-hd{padding:16px 20px;border-bottom:1px solid var(--border);display:flex;align-items:center;gap:10px;background:linear-gradient(90deg,var(--surface3),var(--surface));}
.hist-icon{width:34px;height:34px;border-radius:8px;background:linear-gradient(135deg,#7c3aed,var(--violet));display:grid;place-items:center;font-size:14px;}
table.ht{width:100%;border-collapse:collapse;}
.ht thead tr{background:var(--surface3);}
.ht th{padding:9px 14px;font-size:.58rem;font-weight:800;text-transform:uppercase;letter-spacing:.07em;color:var(--t3);text-align:left;border-bottom:1px solid var(--border2);}
.ht td{padding:10px 14px;font-size:.78rem;border-bottom:1px solid var(--border);vertical-align:middle;}
.ht tr:last-child td{border-bottom:none;}
.ht tr:hover td{background:var(--surface3);}
.td-prev{max-width:200px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;font-family:'DM Mono',monospace;font-size:.68rem;color:var(--t2);}
.td-dt{font-size:.68rem;color:var(--t3);white-space:nowrap;}
.cbadge{display:inline-flex;padding:2px 8px;border-radius:5px;background:var(--violet-pale);color:var(--violet2);font-size:.62rem;font-weight:800;border:1px solid var(--border2);}
.empty-tbl{padding:36px;text-align:center;font-size:.78rem;color:var(--t3);}

@keyframes up{from{opacity:0;transform:translateY(14px)}to{opacity:1;transform:none}}
::-webkit-scrollbar{width:5px;}
::-webkit-scrollbar-track{background:var(--surface2);}
::-webkit-scrollbar-thumb{background:var(--border2);border-radius:4px;}
::-webkit-scrollbar-thumb:hover{background:var(--violet);}
@media(max-width:600px){
  .page{padding:20px 14px 60px;}.topbar{padding:0 14px;}.tb-div{display:none;}
  .rc-body{padding:16px;}.raw-hd{flex-direction:column;align-items:flex-start;}.labs-tbl{font-size:.7rem;}
}
</style>
</head>
<body>

<div class="topbar">
  <div class="tb-logo">🏥</div>
  <div><div class="tb-name">Zaman Medical</div><div class="tb-sub">Healthcare Information System</div></div>
  <div class="tb-div"></div>
  <div class="tb-pill"><div class="tb-dot"></div>Smart OCR Scanner</div>
  <div class="tb-av">ZM</div>
</div>

<div class="page">

  <div class="hdr">
    <div class="badge">⚡ AI · Lab, Radiology &amp; Consultation Reports</div>
    <h1>Upload any report —<br>see <em>all your results</em></h1>
    <p class="hdr-sub">Auto-detects Lab, Radiology, or Consultation reports. Every result shown with colour-coded status — green normal, red high, blue low.</p>
  </div>

  <div class="card">
    <div class="card-lbl">Upload Medical Document</div>
    <form method="POST" enctype="multipart/form-data" id="ocrForm">
      <div class="drop" id="dropZone">
        <input type="file" name="image" id="fileInput" accept="image/*" required onchange="handleFile(this)">
        <div id="dropDefault">
          <div class="drop-ico">📋</div>
          <div class="drop-title">Drop any lab report, CT, MRI or consultation note</div>
          <div class="drop-sub">Blood reports, hormone panels, urine tests, radiology, consultation notes — all formats</div>
          <div class="fmts"><span class="fmt">JPG</span><span class="fmt">PNG</span><span class="fmt">WEBP</span></div>
        </div>
        <div id="prev">
          <img id="prevImg" src="" alt="">
          <div class="prev-info" id="prevName"></div>
        </div>
      </div>
      <div class="scanning" id="scanInd">
        <div class="spin"></div>Scanning &amp; extracting all results…
        <div class="scan-bar"><div class="scan-fill"></div></div>
      </div>
      <button type="submit" class="btn" id="scanBtn">🔍 &nbsp; Scan &amp; Extract All Results</button>
    </form>
  </div>

  <?php if ($error): ?>
  <div class="err">⚠ <?= htmlspecialchars($error) ?></div>
  <?php endif; ?>

  <?php if ($text && !$error): ?>

    <?php if ($summary && !empty($summary['condition_status'])):
      $st  = strtoupper($summary['condition_status']);
      if (!in_array($st, ['CRITICAL','SERIOUS','STABLE'])) $st = 'STABLE';
      $ico = ['CRITICAL'=>'🚨','SERIOUS'=>'⚠️','STABLE'=>'✅'][$st];
      $ttl = ['CRITICAL'=>'CRITICAL — Immediate Attention Required','SERIOUS'=>'SERIOUS — Important Findings Detected','STABLE'=>'STABLE — Results Within Normal Range'][$st];
      $sub = ['CRITICAL'=>'Life-threatening values detected. Act immediately.','SERIOUS'=>'Significant findings — prompt follow-up needed.','STABLE'=>'Values within or close to normal range.'][$st];
    ?>
    <div class="banner <?= $st ?>">
      <div class="banner-ico"><?= $ico ?></div>
      <div>
        <div class="banner-title"><?= $ttl ?></div>
        <div class="banner-sub"><?= $sub ?></div>
      </div>
    </div>
    <?php endif; ?>

    <div class="result-card">
      <div class="rc-head">
        <div class="rc-icon"><?= $reportType==='RADIOLOGY'?'🫁':($reportType==='GENERAL'?'🩺':'🎯') ?></div>
        <div style="flex:1">
          <div class="rc-title">Findings Report</div>
          <div class="rc-sub">
            <?php if ($reportType==='RADIOLOGY'): ?>Radiology report · Key findings extracted · Powered by Gemini
            <?php elseif ($reportType==='GENERAL'): ?>Consultation report · Diagnosis &amp; vitals extracted · Powered by Gemini
            <?php else: ?>All test results extracted · Normal &amp; abnormal · Powered by Gemini<?php endif; ?>
          </div>
        </div>
        <div class="rtype-badge <?= $reportType ?>">
          <?php if ($reportType==='RADIOLOGY'): ?>🩻 Radiology
          <?php elseif ($reportType==='GENERAL'): ?>🩺 Consultation
          <?php else: ?>🧪 Lab Report<?php endif; ?>
        </div>
      </div>

      <div class="rc-body">

        <?php if (!empty($patientInfo['name']) || !empty($patientInfo['age_gender'])): ?>
        <div>
          <div class="sec-lbl">Patient</div>
          <div class="pt-grid">
            <?php if (!empty($patientInfo['name'])): ?><div class="pt-box"><div class="pt-lbl">Name</div><div class="pt-val"><?= htmlspecialchars($patientInfo['name']) ?></div></div><?php endif; ?>
            <?php if (!empty($patientInfo['age_gender'])): ?><div class="pt-box"><div class="pt-lbl">Age / Gender</div><div class="pt-val"><?= htmlspecialchars($patientInfo['age_gender']) ?></div></div><?php endif; ?>
            <?php if (!empty($patientInfo['referred_by'])): ?><div class="pt-box"><div class="pt-lbl">Referred By</div><div class="pt-val"><?= htmlspecialchars($patientInfo['referred_by']) ?></div></div><?php endif; ?>
          </div>
        </div>
        <?php endif; ?>

        <?php if (!empty($summary['all_results'])): ?>
        <div>
          <div class="sec-lbl">🧾 Extracted Results</div>
          <div class="res-legend">
            <div class="res-legend-item"><div class="res-dot norm"></div> Normal</div>
            <div class="res-legend-item"><div class="res-dot high"></div> High ▲</div>
            <div class="res-legend-item"><div class="res-dot low"></div> Low ▼</div>
            <div class="res-legend-item"><div class="res-dot abn"></div> Abnormal</div>
          </div>
          <table class="labs-tbl">
            <thead><tr><th>Test</th><th>Result</th><th>Reference Range</th><th>Status</th></tr></thead>
            <tbody>
            <?php foreach ($summary['all_results'] as $row):
              $flagRaw = strtoupper(trim($row['flag'] ?? 'NORMAL'));
              if ($flagRaw === 'HIGH') {
                  $fc = 'H'; $rowClass = 'row-high'; $arrow = '▲'; $color = '#dc2626';
              } elseif ($flagRaw === 'LOW') {
                  $fc = 'L'; $rowClass = 'row-low';  $arrow = '▼'; $color = '#2563eb';
              } elseif ($flagRaw === 'ABNORMAL') {
                  $fc = 'A'; $rowClass = 'row-abnormal'; $arrow = '⚠'; $color = '#ea580c';
              } else {
                  $fc = 'N'; $rowClass = 'row-normal'; $arrow = '✓'; $color = '#16a34a';
              }
            ?>
            <tr class="<?= $rowClass ?>">
              <td class="t-name"><?= htmlspecialchars($row['test'] ?? '') ?></td>
              <td><span style="color:<?= $color ?>;font-weight:800;font-family:'DM Mono',monospace;"><?= htmlspecialchars($row['result'] ?? '') ?></span></td>
              <td><span class="t-range"><?= htmlspecialchars($row['range'] ?? '') ?></span></td>
              <td><span class="flag <?= $fc ?>"><?= $arrow ?> <?= $flagRaw ?: 'NORMAL' ?></span></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        </div>
        <?php endif; ?>

        <?php if ($reportType === 'LAB' && empty($summary['all_results']) && $summary): ?>
        <div class="all-normal">✅ No test result lines could be matched. Try a clearer image.</div>
        <?php elseif (($reportType === 'RADIOLOGY' || $reportType === 'GENERAL') && !empty($summary['critical_findings'])): ?>
        <div>
          <div class="sec-lbl"><?= $reportType==='RADIOLOGY' ? '🩻 Key Imaging Findings' : '🩺 Key Clinical Findings' ?></div>
          <div class="findings-list">
            <?php foreach ($summary['critical_findings'] as $cf): if (trim($cf)): ?>
            <div class="finding-item"><div class="finding-dot"></div><div class="finding-txt"><?= htmlspecialchars($cf) ?></div></div>
            <?php endif; endforeach; ?>
          </div>
        </div>
        <?php elseif ($reportType === 'RADIOLOGY' && $summary): ?>
        <div class="all-normal">✅ No significant abnormality detected in imaging report</div>
        <?php elseif ($reportType === 'GENERAL' && $summary && empty($summary['all_results'])): ?>
        <div class="all-normal">✅ No significant clinical findings flagged</div>
        <?php endif; ?>

        <?php if (!empty($summary['diagnosis'])): ?>
        <div>
          <div class="sec-lbl">🩺 Diagnosis / Impression</div>
          <div class="tags">
            <?php foreach ($summary['diagnosis'] as $d): if (trim($d)): ?>
            <span class="tag <?= $reportType==='LAB'?'dx':'rad' ?>"><?= $reportType==='LAB'?'🔴':'🩻' ?> <?= htmlspecialchars($d) ?></span>
            <?php endif; endforeach; ?>
          </div>
        </div>
        <?php endif; ?>

        <?php if (!empty($summary['urgent_actions'])): ?>
        <div>
          <div class="sec-lbl"><?= $reportType==='GENERAL'?'📋 Follow-up &amp; Advice':'🚑 Urgent Clinical Actions' ?></div>
          <div class="act-list">
            <?php foreach ($summary['urgent_actions'] as $i => $a): if (trim($a)): ?>
            <div class="act-item">
              <div class="act-num"><?= $i+1 ?></div>
              <div class="act-txt"><?= htmlspecialchars($a) ?></div>
            </div>
            <?php endif; endforeach; ?>
          </div>
        </div>
        <?php endif; ?>

      </div>
    </div>

    <!-- RAW OCR PANEL -->
    <div class="raw-card">
      <div class="raw-hd" onclick="toggleRaw()">
        <div class="raw-hl">
          <div class="raw-icon">🔬</div>
          <div>
            <div class="rc-title">Raw OCR Text (Reference)</div>
            <div class="rc-sub">
              <?php if ($reportType==='LAB'): ?>Abnormal HIGH = red · LOW = blue · Normal = green
              <?php elseif ($reportType==='RADIOLOGY'): ?>Critical findings = red · Other = grey
              <?php else: ?>Key findings = red · Other = grey<?php endif; ?>
            </div>
          </div>
        </div>
        <div class="raw-stats">
          <div class="stat"><?= $wordCount ?> words</div>
          <div class="stat"><?= $charCount ?> chars</div>
          <div class="sv-badge">✓ Saved</div>
          <div class="raw-toggle" id="rawToggle">
            Show <svg width="12" height="12" viewBox="0 0 12 12" fill="none"><path d="M2 4L6 8L10 4" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
          </div>
        </div>
      </div>
      <div class="raw-body" id="rawBody">
        <div class="hl-legend">
          <?php if ($reportType==='LAB'): ?>
          <div class="hl-legend-item"><div class="hl-dot abn"></div> High</div>
          <div class="hl-legend-item"><div class="hl-dot low"></div> Low</div>
          <div class="hl-legend-item"><div class="hl-dot norm"></div> Normal</div>
          <?php else: ?>
          <div class="hl-legend-item"><div class="hl-dot abn"></div> Critical finding</div>
          <div class="hl-legend-item"><div class="hl-dot rad"></div> Report text</div>
          <?php endif; ?>
        </div>
        <div class="hl-results"><?= $highlightedOCR ?></div>
      </div>
    </div>

  <?php else: ?>
    <?php if (!$error): ?>
    <div class="empty">
      <div class="empty-ico">📋</div>
      <h3>No scan yet</h3>
      <p>Upload any medical report — blood work, hormone panels, CT, MRI, or consultation notes.</p>
    </div>
    <?php endif; ?>
  <?php endif; ?>

  <div class="hist-card">
    <div class="hist-hd">
      <div class="hist-icon">🕘</div>
      <div><div class="rc-title">Scan History</div><div class="rc-sub">Last 10 scans</div></div>
    </div>
    <?php if (count($history) > 0): ?>
    <table class="ht">
      <thead><tr><th>#</th><th>Preview</th><th>Words</th><th>Chars</th><th>Date</th></tr></thead>
      <tbody>
        <?php foreach ($history as $i => $row): ?>
        <tr>
          <td style="color:var(--t3);font-size:.68rem;font-weight:700"><?= $i+1 ?></td>
          <td><div class="td-prev"><?= htmlspecialchars($row['extracted_text']) ?></div></td>
          <td><span class="cbadge"><?= (int)$row['word_count'] ?></span></td>
          <td><span class="cbadge"><?= (int)$row['char_count'] ?></span></td>
          <td><div class="td-dt"><?= date('d M Y, h:i A', strtotime($row['scanned_at'])) ?></div></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    <?php else: ?>
    <div class="empty-tbl">No history yet.</div>
    <?php endif; ?>
  </div>

</div>

<script>
function handleFile(input) {
  if (!input.files || !input.files[0]) return;
  const f = input.files[0];
  document.getElementById('prevImg').src = URL.createObjectURL(f);
  document.getElementById('prevName').textContent = f.name + ' · ' + (f.size/1024).toFixed(1) + ' KB';
  document.getElementById('dropDefault').style.display = 'none';
  document.getElementById('prev').style.display = 'block';
}
document.getElementById('ocrForm').addEventListener('submit', function() {
  document.getElementById('scanBtn').style.display = 'none';
  document.getElementById('scanInd').style.display = 'flex';
});
const dz = document.getElementById('dropZone');
dz.addEventListener('dragover', e => { e.preventDefault(); dz.classList.add('over'); });
dz.addEventListener('dragleave', () => dz.classList.remove('over'));
dz.addEventListener('drop', e => {
  e.preventDefault(); dz.classList.remove('over');
  const fi = document.getElementById('fileInput');
  const dt = new DataTransfer();
  dt.items.add(e.dataTransfer.files[0]);
  fi.files = dt.files; handleFile(fi);
});
function toggleRaw() {
  const body   = document.getElementById('rawBody');
  const toggle = document.getElementById('rawToggle');
  const open   = body.classList.toggle('open');
  toggle.classList.toggle('open', open);
  toggle.firstChild.textContent = open ? 'Hide ' : 'Show ';
}
</script>
</body>
</html>