<?php
session_start();
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'doctor') {
    header("Location:../auth/login.php");
    exit();
}
error_reporting(E_ALL);
ini_set('display_errors', 1);

$conn = new mysqli("localhost", "root", "", "SHAPMS");
if ($conn->connect_error) die("DB Error: " . $conn->connect_error);

$doctor_id = (int)$_SESSION['user_id'];

// ── Get selected patient (from GET param or first patient)
$selected_patient_id = isset($_GET['patient_id']) ? (int)$_GET['patient_id'] : 0;
$selected_scan_id    = isset($_GET['scan_id'])    ? (int)$_GET['scan_id']    : 0;

// ── Get all patients who have scans
$patients = [];
$pRes = $conn->query("
    SELECT DISTINCT u.user_id AS id, u.full_name AS name, u.email
    FROM users u
    INNER JOIN ocr_scans o ON o.patient_id = u.user_id
    WHERE u.role = 'patient'
    ORDER BY u.full_name ASC
");
if ($pRes) while ($row = $pRes->fetch_assoc()) $patients[] = $row;

// ── Auto-select first patient if none selected
if (!$selected_patient_id && count($patients) > 0) {
    $selected_patient_id = $patients[0]['id'];
}

// ── Get scans for selected patient
$scans = [];
if ($selected_patient_id) {
    $sRes = $conn->prepare("SELECT * FROM ocr_scans WHERE patient_id = ? ORDER BY scanned_at DESC");
    $sRes->bind_param("i", $selected_patient_id);
    $sRes->execute();
    $result = $sRes->get_result();
    while ($row = $result->fetch_assoc()) $scans[] = $row;
    $sRes->close();
}

// ── Auto-select latest scan if none selected
if (!$selected_scan_id && count($scans) > 0) {
    $selected_scan_id = $scans[0]['id'];
}

// ── Get selected scan data
$currentScan    = null;
$summary        = null;
$reportType     = 'LAB';
$highlightedOCR = '';

if ($selected_scan_id) {
    $scStmt = $conn->prepare("SELECT * FROM ocr_scans WHERE id = ? AND patient_id = ?");
    $scStmt->bind_param("ii", $selected_scan_id, $selected_patient_id);
    $scStmt->execute();
    $scRes = $scStmt->get_result();
    $currentScan = $scRes->fetch_assoc();
    $scStmt->close();

    if ($currentScan && !empty($currentScan['ai_summary'])) {
        $summary = json_decode($currentScan['ai_summary'], true);
        $reportType = $summary['report_type'] ?? 'LAB';
    }
}

// ── Get selected patient info
$selectedPatient = null;
foreach ($patients as $p) {
    if ((int)$p['id'] === (int)$selected_patient_id) { $selectedPatient = $p; break; }
}

// ════════════
// Build OCR highlighted panel — LAB
// ════════════
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
        if ($matchedFlag === 'HIGH') {
            $output .= '<div class="hl-row hl-abnormal">▲ ' . $escaped . '</div>';
        } elseif ($matchedFlag === 'LOW') {
            $output .= '<div class="hl-row hl-low">▼ ' . $escaped . '</div>';
        } elseif ($matchedFlag === 'ABNORMAL') {
            $output .= '<div class="hl-row hl-abnormal">⚠ ' . $escaped . '</div>';
        }
        // Normal / unmatched lines are skipped — only abnormal results shown
    }
return $output ?: '<div class="hl-empty">✅ All results within normal range — nothing abnormal to flag.</div>';
}

// ════════════
// Build OCR highlighted panel — RADIOLOGY
// ════════════
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
        '/not\s+valid\s+for\s+medico.legal/i',
    ];
    $inlineSkip = [
        '/^\s*[•\*]+\s*$/',
        '/^\s*ph\s*[:\-]\s*\d/i', '/www\.|https?:\/\//i',
        '/patient\s+(name|id|no)\s*[:\|]/i', '/age\s*[\/\\\\]\s*gender/i',
        '/referred\s+by\s*[:\|]/i', '/study\s+date|report\s+date/i',
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
        if (preg_match('/lesion|lymphaden|metastati|neoplasti|effusion|emphysem|nodule|\bmass\b|tumou?r|obstruct|infarct|hemorrhage|fracture|collaps|opacity|consolidat|calcif|atrophy|stenosis/i', $line)) $isCritical = true;
        if (preg_match('/^\s*\d+\.\s+[A-Z]/', $line)) $isCritical = true;
        if ($isCritical) {
            $escaped = htmlspecialchars($line);
            $output .= '<div class="hl-row hl-abnormal">⚠ ' . $escaped . '</div>';
        }
    }
    if (!$output) {
        foreach ($lines as $line) {
            $line = trim($line);
            if (strlen($line) < 8) continue;
            $lineLower = strtolower($line); $isCritical = false;
            foreach ($criticalPhrases as $phrase) { if (strpos($lineLower, $phrase) !== false) { $isCritical = true; break; } }
            if (preg_match('/lesion|lymphaden|metastati|neoplasti|effusion|emphysem|nodule|\bmass\b|tumou?r|obstruct|infarct|hemorrhage|fracture|collaps|opacity|consolidat|calcif|atrophy|stenosis/i', $line)) $isCritical = true;
            $escaped = htmlspecialchars($line);
            $output .= $isCritical
                ? '<div class="hl-row hl-abnormal">⚠ ' . $escaped . '</div>'
                : '<div class="hl-row hl-rad-normal">' . $escaped . '</div>';
        }
    }
   return $output ?: '<div class="hl-empty">✅ No critical findings detected.</div>';
}

// ════════════
// Build OCR highlighted panel — GENERAL
// ════════════
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
        '/patient\s+id|sample\s+type|barcode/i',
        '/^\s*\d{6,}\s*\/\s*\d+\s*$/', '/^\s*\d{10}\s*$/',
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
        // non-critical, non-header lines are skipped
    }
    return $output ?: '<div class="hl-empty">No report content could be extracted.</div>';
}

// ── Build highlighted OCR for current scan
if ($currentScan && !empty($currentScan['extracted_text'])) {
    $rawText = $currentScan['extracted_text'];
    if ($summary) {
        if ($reportType === 'LAB') {
            $highlightedOCR = buildHighlightedLabOCR($rawText, $summary);
        } elseif ($reportType === 'RADIOLOGY') {
            $highlightedOCR = buildHighlightedRadiologyOCR($rawText, $summary);
        } else {
            $highlightedOCR = buildHighlightedGeneralOCR($rawText, $summary);
        }
    } else {
        foreach (explode("\n", $rawText) as $line) {
            $line = trim($line);
            if ($line === '') { $highlightedOCR .= '<div class="hl-spacer"></div>'; continue; }
            $highlightedOCR .= '<div class="hl-row hl-rad-normal">' . htmlspecialchars($line) . '</div>';
        }
    }
} elseif ($currentScan) {
    $highlightedOCR = '<div class="hl-empty">No extracted text found for this scan.</div>';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Patient Reports — Doctor View</title>
<link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700;800&family=DM+Mono:wght@400;500&display=swap" rel="stylesheet">
<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" rel="stylesheet">
<style>
:root{
    /* Two shades of blue + purplish blue theme from report file */
    --b1: #0a0f2a;
    --b2: #11163d;
    --b3: #1a237e;
    --b4: #283593;
    --b5: #3949ab;
    --b6: #5c6bc0;
    --b7: #7986cb;
    --b8: #9fa8da;
    --b9: #c5cae9;
    --acc: #7e57c2;
    --acc2: #b39ddb;
    --acc3: #9575cd;
    --glow: rgba(57,73,171,.6);
    --glass: rgba(255,255,255,.045);
    --gb: rgba(255,255,255,.09);
    --gh: rgba(255,255,255,.07);
    --txt: #e8eaf6;
    --mut: #9fa8da;
    --r: 20px;
    --mono: 'DM Mono', monospace;
}
*,*::before,*::after{margin:0;padding:0;box-sizing:border-box;}
body{
    font-family:'Outfit',sans-serif;
    background:var(--b1);
    color:var(--txt);
    min-height:100vh;
    position:relative;
}
body::before{
    content:'';position:fixed;inset:0;
    background:
        radial-gradient(ellipse 90% 70% at 10% 10%, rgba(57,73,171,.28) 0%,transparent 55%),
        radial-gradient(ellipse 70% 60% at 90% 80%, rgba(126,87,194,.22) 0%,transparent 55%),
        radial-gradient(ellipse 50% 50% at 55% 45%, rgba(26,35,126,.5) 0%,transparent 70%);
    pointer-events:none;z-index:0;
}
body::after{
    content:'';position:fixed;inset:0;
    background-image:
        linear-gradient(rgba(255,255,255,.018) 1px,transparent 1px),
        linear-gradient(90deg,rgba(255,255,255,.018) 1px,transparent 1px);
    background-size:52px 52px;
    pointer-events:none;z-index:0;
}

/* TOPBAR */
.topbar{
    position:relative;z-index:2;
    background:linear-gradient(120deg, rgba(26,35,126,.92) 0%, rgba(57,73,171,.85) 100%);
    backdrop-filter:blur(12px);
    color:#fff;padding:0 28px;display:flex;align-items:center;
    min-height:62px;border-bottom:1px solid var(--gb);position:sticky;top:0;
}
.topbar::after{
    content:'';position:absolute;bottom:0;left:0;right:0;height:2px;
    background:linear-gradient(90deg,var(--b5),var(--acc),var(--b5));
}
.tb-logo{
    width:36px;height:36px;border-radius:9px;
    background:rgba(255,255,255,.1);border:1px solid var(--gb);
    display:grid;place-items:center;font-size:17px;margin-right:12px;
}
.tb-name{font-size:.92rem;font-weight:800;color:#fff;}
.tb-sub{font-size:.62rem;color:var(--b9);}
.tb-div{width:1px;height:26px;background:var(--gb);margin:0 20px;}
.tb-pill{
    font-size:.6rem;font-weight:800;letter-spacing:.1em;text-transform:uppercase;
    padding:4px 12px;border-radius:40px;
    background:rgba(126,87,194,.25);border:1px solid var(--acc);
    color:var(--acc2);display:flex;align-items:center;gap:6px;
}
.tb-dot{width:5px;height:5px;border-radius:50%;background:var(--acc2);animation:blink 2s infinite;}
@keyframes blink{0%,100%{opacity:1}50%{opacity:.2}}
.tb-av{
    margin-left:auto;width:32px;height:32px;border-radius:8px;
    background:linear-gradient(135deg,var(--b5),var(--acc));
    display:grid;place-items:center;font-size:.7rem;font-weight:800;color:#fff;
}

/* LAYOUT */
.layout{display:grid;grid-template-columns:260px 1fr;min-height:calc(100vh - 62px);position:relative;z-index:1;}

/* SIDEBAR */
.sidebar{
    background:var(--glass);backdrop-filter:blur(22px);
    border-right:1px solid var(--gb);
    display:flex;flex-direction:column;height:calc(100vh - 62px);
    position:sticky;top:62px;overflow:hidden;
}
.sb-head{
    padding:16px;border-bottom:1px solid var(--gb);
    background:rgba(255,255,255,.03);
}
.sb-title{
    font-size:.7rem;font-weight:800;text-transform:uppercase;
    letter-spacing:.08em;color:var(--mut);margin-bottom:10px;
    display:flex;align-items:center;gap:6px;
}
.sb-title::before{
    content:'';width:10px;height:2px;
    background:linear-gradient(90deg,var(--b5),var(--acc));border-radius:2px;
}
.sb-search{
    width:100%;padding:8px 12px;border:1px solid var(--gb);
    border-radius:8px;font-size:.75rem;font-family:'Outfit',sans-serif;
    color:var(--txt);background:rgba(255,255,255,.03);outline:none;
}
.sb-search:focus{
    border-color:var(--acc);box-shadow:0 0 0 3px rgba(126,87,194,.2);
}
.sb-patients{flex:1;overflow-y:auto;padding:8px;}
.pt-item{
    display:flex;align-items:center;gap:10px;padding:10px 12px;
    border-radius:10px;cursor:pointer;transition:all .15s;margin-bottom:4px;
    text-decoration:none;color:var(--txt);
}
.pt-item:hover{background:rgba(255,255,255,.05);}
.pt-item.active{
    background:rgba(57,73,171,.3);border:1px solid var(--b6);
}
.pt-avatar{
    width:34px;height:34px;border-radius:9px;
    background:linear-gradient(135deg,var(--b5),var(--acc));
    display:grid;place-items:center;font-size:.72rem;font-weight:800;color:#fff;flex-shrink:0;
}
.pt-name{font-size:.78rem;font-weight:700;color:#fff;}
.pt-email{font-size:.62rem;color:var(--mut);margin-top:1px;}
.sb-empty{padding:24px;text-align:center;font-size:.75rem;color:var(--mut);}

/* MAIN */
.main{padding:28px;overflow-y:auto;}

/* PATIENT HEADER */
.pt-hdr{
    background:var(--glass);backdrop-filter:blur(22px);
    border:1px solid var(--gb);border-radius:var(--r);
    padding:20px 24px;margin-bottom:20px;display:flex;
    align-items:center;gap:16px;
}
.pt-hdr-av{
    width:52px;height:52px;border-radius:13px;
    background:linear-gradient(135deg,var(--b5),var(--acc));
    display:grid;place-items:center;font-size:1.2rem;font-weight:800;color:#fff;flex-shrink:0;
}
.pt-hdr-name{font-size:1.1rem;font-weight:800;color:#fff;}
.pt-hdr-meta{font-size:.72rem;color:var(--mut);margin-top:3px;}
.pt-hdr-right{margin-left:auto;display:flex;gap:8px;flex-wrap:wrap;}
.scan-count{
    display:inline-flex;align-items:center;gap:5px;
    background:rgba(57,73,171,.3);border:1px solid var(--b6);
    color:var(--b7);border-radius:8px;padding:6px 12px;
    font-size:.7rem;font-weight:800;
}

/* SCAN TABS */
.scan-tabs{display:flex;gap:6px;margin-bottom:20px;overflow-x:auto;padding-bottom:4px;}
.scan-tab{
    display:inline-flex;flex-direction:column;align-items:flex-start;
    padding:8px 14px;border-radius:10px;border:1px solid var(--gb);
    background:var(--glass);cursor:pointer;text-decoration:none;
    transition:all .15s;white-space:nowrap;min-width:120px;color:var(--txt);
}
.scan-tab:hover{border-color:var(--acc);background:rgba(255,255,255,.05);}
.scan-tab.active{
    border-color:var(--acc);background:rgba(57,73,171,.3);
    box-shadow:0 0 0 3px rgba(126,87,194,.2);
}
.scan-tab-date{font-size:.68rem;font-weight:800;color:#fff;}
.scan-tab-meta{font-size:.58rem;color:var(--mut);margin-top:2px;}
.rtype-dot{display:inline-block;width:6px;height:6px;border-radius:50%;margin-right:4px;}
.rtype-dot.LAB{background:var(--acc);}
.rtype-dot.RADIOLOGY{background:var(--b6);}
.rtype-dot.GENERAL{background:#ec4899;}

/* CARDS */
.card{
    background:var(--glass);backdrop-filter:blur(22px);
    border:1px solid var(--gb);border-radius:var(--r);
    margin-bottom:18px;overflow:hidden;
}
.card-head{
    padding:14px 20px;border-bottom:1px solid var(--gb);
    background:rgba(255,255,255,.03);display:flex;
    align-items:center;gap:10px;flex-wrap:wrap;
}
.card-icon{
    width:34px;height:34px;border-radius:9px;
    background:linear-gradient(135deg,var(--b5),var(--acc));
    display:grid;place-items:center;font-size:15px;flex-shrink:0;color:#fff;
}
.card-title{font-size:.82rem;font-weight:800;color:#fff;}
.card-sub{font-size:.63rem;color:var(--mut);margin-top:1px;}

/* STATUS BANNER */
.banner{padding:14px 18px;border-radius:var(--r);display:flex;align-items:center;gap:12px;margin-bottom:18px;}
.banner.CRITICAL{background:rgba(239,68,68,.15);border:1px solid rgba(239,68,68,.3);border-left:4px solid #f44336;}
.banner.SERIOUS{background:rgba(255,152,0,.15);border:1px solid rgba(255,152,0,.3);border-left:4px solid #ff9800;}
.banner.STABLE{background:rgba(126,87,194,.15);border:1px solid var(--acc);border-left:4px solid var(--acc);}
.banner-ico{font-size:22px;flex-shrink:0;}
.banner-title{font-size:.88rem;font-weight:800;color:#fff;}
.banner-sub{font-size:.68rem;color:var(--mut);margin-top:2px;}

/* REPORT TYPE BADGE */
.rtype-badge{
    display:inline-flex;align-items:center;gap:6px;padding:4px 12px;
    border-radius:7px;font-size:.62rem;font-weight:800;
    letter-spacing:.07em;text-transform:uppercase;
}
.rtype-badge.LAB{background:rgba(126,87,194,.25);color:var(--acc2);border:1px solid var(--acc);}
.rtype-badge.RADIOLOGY{background:rgba(57,73,171,.3);color:var(--b7);border:1px solid var(--b6);}
.rtype-badge.GENERAL{background:rgba(236,72,153,.2);color:#f472b6;border:1px solid rgba(236,72,153,.3);}

/* SEC LABEL */
.sec-lbl{
    font-size:.6rem;font-weight:800;text-transform:uppercase;
    letter-spacing:.1em;color:var(--mut);margin-bottom:10px;
    display:flex;align-items:center;gap:6px;
}
.sec-lbl::before{
    content:'';width:10px;height:2px;
    background:linear-gradient(90deg,var(--b5),var(--acc));border-radius:2px;
}

/* RESULTS TABLE */
.labs-tbl{width:100%;border-collapse:collapse;}
.labs-tbl th{
    padding:9px 14px;font-size:.6rem;font-weight:800;text-transform:uppercase;
    letter-spacing:.07em;color:var(--mut);text-align:left;
    border-bottom:1px solid var(--gb);background:rgba(255,255,255,.03);
}
.labs-tbl td{padding:11px 14px;font-size:.8rem;border-bottom:1px solid var(--gb);vertical-align:middle;color:var(--txt);}
.labs-tbl tr:last-child td{border-bottom:none;}
.labs-tbl tr.row-normal td{background:rgba(126,87,194,.1);}
.labs-tbl tr.row-high td{background:rgba(239,68,68,.15);}
.labs-tbl tr.row-low td{background:rgba(37,99,235,.1);}
.labs-tbl tr.row-abnormal td{background:rgba(255,152,0,.15);}
.t-name{font-weight:700;color:var(--b9);}
.t-range{font-size:.68rem;color:var(--mut);font-family:var(--mono);}
.flag{display:inline-flex;align-items:center;gap:3px;padding:3px 9px;border-radius:5px;font-size:.6rem;font-weight:800;}
.flag.H{background:rgba(239,68,68,.2);color:#fca5a5;border:1px solid rgba(239,68,68,.3);}
.flag.L{background:rgba(37,99,235,.15);color:var(--b7);border:1px solid rgba(37,99,235,.3);}
.flag.A{background:rgba(255,152,0,.2);color:#fdba74;border:1px solid rgba(255,152,0,.3);}
.flag.N{background:rgba(126,87,194,.25);color:var(--acc2);border:1px solid var(--acc);}

/* LEGEND */
.res-legend{display:flex;gap:12px;margin-bottom:12px;flex-wrap:wrap;}
.res-legend-item{display:flex;align-items:center;gap:5px;font-size:.65rem;font-weight:700;color:var(--mut);}
.res-dot{width:10px;height:10px;border-radius:3px;}
.res-dot.norm{background:rgba(126,87,194,.25);border:1px solid var(--acc);}
.res-dot.high{background:rgba(239,68,68,.2);border:1px solid rgba(239,68,68,.3);}
.res-dot.low{background:rgba(37,99,235,.15);border:1px solid rgba(37,99,235,.3);}
.res-dot.abn{background:rgba(255,152,0,.2);border:1px solid rgba(255,152,0,.3);}

/* TAGS */
.tags{display:flex;flex-wrap:wrap;gap:7px;}
.tag{display:inline-flex;align-items:center;gap:5px;padding:6px 11px;border-radius:8px;font-size:.7rem;font-weight:700;border:1px solid;}
.tag.dx{background:rgba(239,68,68,.2);color:#fca5a5;border-color:rgba(239,68,68,.3);}
.tag.rad{background:rgba(57,73,171,.3);color:var(--b7);border-color:var(--b6);}

/* FINDINGS */
.findings-list{display:flex;flex-direction:column;gap:7px;}
.finding-item{
    display:flex;align-items:flex-start;gap:10px;
    background:rgba(239,68,68,.15);border:1px solid rgba(239,68,68,.3);
    border-radius:9px;padding:10px 14px;
}
.finding-dot{width:8px;height:8px;border-radius:50%;background:#f44336;flex-shrink:0;margin-top:4px;}
.finding-txt{font-size:.78rem;font-weight:600;color:#fca5a5;line-height:1.5;}

/* ACTIONS */
.act-list{display:flex;flex-direction:column;gap:8px;}
.act-item{
    display:flex;align-items:flex-start;gap:10px;
    background:rgba(126,87,194,.15);border:1px solid var(--acc);
    border-radius:9px;padding:10px 14px;
}
.act-num{
    width:22px;height:22px;border-radius:6px;
    background:linear-gradient(135deg,var(--b5),var(--acc));
    display:grid;place-items:center;font-size:.62rem;font-weight:800;color:#fff;flex-shrink:0;
}
.act-txt{font-size:.77rem;font-weight:600;color:var(--acc2);line-height:1.5;}
.all-normal{text-align:center;padding:24px;background:rgba(126,87,194,.15);border:1px solid var(--acc);border-radius:var(--r);color:var(--acc2);font-weight:700;font-size:.82rem;}

/* OCR PANEL */
.ocr-toggle{
    display:inline-flex;align-items:center;gap:5px;
    background:rgba(57,73,171,.3);border:1px solid var(--b6);
    color:var(--b7);border-radius:7px;padding:5px 12px;
    font-size:.68rem;font-weight:800;cursor:pointer;margin-left:auto;
}
.ocr-toggle svg{transition:transform .2s;}
.ocr-toggle.open svg{transform:rotate(180deg);}
.ocr-body{display:none;padding:16px;}
.ocr-body.open{display:block;}
.hl-legend{display:flex;gap:12px;margin-bottom:10px;flex-wrap:wrap;}
.hl-legend-item{display:flex;align-items:center;gap:5px;font-size:.65rem;font-weight:700;color:var(--mut);}
.hl-dot{width:10px;height:10px;border-radius:3px;}
.hl-dot.abn{background:rgba(239,68,68,.2);border:1px solid rgba(239,68,68,.3);}
.hl-dot.low{background:rgba(37,99,235,.15);border:1px solid rgba(37,99,235,.3);}
.hl-dot.norm{background:rgba(126,87,194,.25);border:1px solid var(--acc);}
.hl-dot.rad{background:rgba(255,255,255,.03);border:1px solid var(--gb);}
.hl-results{display:flex;flex-direction:column;gap:4px;max-height:380px;overflow-y:auto;}
.hl-row{
    padding:7px 12px;border-radius:7px;font-family:var(--mono);
    font-size:.73rem;line-height:1.5;border-left:3px solid transparent;color:var(--txt);
}
.hl-abnormal{background:rgba(239,68,68,.15);border-left-color:#f44336;color:#fca5a5;font-weight:700;}
.hl-low{background:rgba(37,99,235,.1);border-left-color:var(--b6);color:var(--b7);font-weight:700;}
.hl-normal{background:rgba(126,87,194,.15);border-left-color:var(--acc);color:var(--acc2);font-weight:500;}
.hl-rad-normal{background:rgba(255,255,255,.03);border-left-color:var(--gb);color:var(--mut);}
.hl-section-hd{
    padding:5px 12px;font-size:.6rem;font-weight:800;text-transform:uppercase;
    letter-spacing:.08em;color:var(--b7);background:rgba(255,255,255,.03);
    border-radius:5px;border-left:3px solid var(--b6);margin-top:6px;
}
.hl-spacer{height:4px;}
.hl-empty{color:var(--mut);font-size:.75rem;padding:12px;}

/* RAW TEXT */
.raw-text{
    font-family:var(--mono);font-size:.7rem;color:var(--mut);
    line-height:1.7;white-space:pre-wrap;word-break:break-word;
    max-height:300px;overflow-y:auto;background:rgba(255,255,255,.03);
    border:1px solid var(--gb);border-radius:var(--r);padding:14px;
}

/* EMPTY STATE */
.empty-state{text-align:center;padding:60px 28px;color:var(--mut);}
.empty-state.ico{font-size:48px;margin-bottom:16px;}
.empty-state h3{font-size:.95rem;font-weight:700;color:var(--b9);margin-bottom:6px;}
.empty-state p{font-size:.75rem;line-height:1.6;}

/* STATS ROW */
.stats-row{display:grid;grid-template-columns:repeat(auto-fill,minmax(130px,1fr));gap:10px;margin-bottom:18px;}
.stat-box{
    background:var(--glass);backdrop-filter:blur(22px);
    border:1px solid var(--gb);border-radius:var(--r);
    padding:14px 16px;
}
.stat-lbl{
    font-size:.58rem;font-weight:800;text-transform:uppercase;
    letter-spacing:.08em;color:var(--mut);margin-bottom:4px;
}
.stat-val{font-size:1.1rem;font-weight:800;color:#fff;}
.stat-val.red{color:#fca5a5;}
.stat-val.green{color:var(--acc2);}
.stat-val.blue{color:var(--b7);}

@keyframes up{from{opacity:0;transform:translateY(10px)}to{opacity:1;transform:none}}
.card{animation:up.3s both;}
::-webkit-scrollbar{width:4px;height:4px;}
::-webkit-scrollbar-track{background:rgba(255,255,255,.03);}
::-webkit-scrollbar-thumb{background:var(--gb);border-radius:4px;}
::-webkit-scrollbar-thumb:hover{background:var(--acc);}

@media(max-width:768px){
 .layout{grid-template-columns:1fr;}
 .sidebar{height:auto;position:static;border-right:none;border-bottom:1px solid var(--gb);}
 .sb-patients{max-height:200px;}
 .main{padding:16px;}
}
</style>
</head>
<body>

<div class="topbar">
  <div class="tb-logo"><i class="fa-solid fa-hospital"></i></div>
  <div><div class="tb-name">Zaman Medical</div><div class="tb-sub">Healthcare Information System</div></div>
  <div class="tb-div"></div>
  <div class="tb-pill"><div class="tb-dot"></div>Doctor — Patient Reports</div>
  <div class="tb-av">DR</div>
</div>

<div class="layout">

  <!-- SIDEBAR: patient list -->
  <div class="sidebar">
    <div class="sb-head">
      <div class="sb-title">Patients</div>
      <input class="sb-search" type="text" placeholder="Search patient…" oninput="filterPatients(this.value)">
    </div>
    <div class="sb-patients" id="patientList">
      <?php if (count($patients) === 0):?>
      <div class="sb-empty">No patients with scans yet.</div>
      <?php else:?>
      <?php foreach ($patients as $p):
        $initials = strtoupper(implode('', array_map(fn($w) => $w[0], explode(' ', trim($p['name'])))));
        $initials = substr($initials, 0, 2);
        $isActive = (int)$p['id'] === (int)$selected_patient_id;
     ?>
      <a class="pt-item <?= $isActive? 'active' : ''?>"
         href="?patient_id=<?= $p['id']?>"
         data-name="<?= strtolower(htmlspecialchars($p['name']))?>">
        <div class="pt-avatar"><?= htmlspecialchars($initials)?></div>
        <div>
          <div class="pt-name"><?= htmlspecialchars($p['name'])?></div>
          <div class="pt-email"><?= htmlspecialchars($p['email'])?></div>
        </div>
      </a>
      <?php endforeach;?>
      <?php endif;?>
    </div>
  </div>

  <!-- MAIN CONTENT -->
  <div class="main">

    <?php if (!$selectedPatient):?>
    <div class="empty-state">
      <div class="ico"><i class="fa-solid fa-user-doctor"></i></div>
      <h3>Select a patient</h3>
      <p>Choose a patient from the sidebar to view their OCR scanned reports.</p>
    </div>

    <?php else:?>

    <!-- Patient header -->
    <?php
      $initials = strtoupper(implode('', array_map(fn($w) => $w[0], explode(' ', trim($selectedPatient['name'])))));
      $initials = substr($initials, 0, 2);
   ?>
    <div class="pt-hdr">
      <div class="pt-hdr-av"><?= htmlspecialchars($initials)?></div>
      <div>
        <div class="pt-hdr-name"><?= htmlspecialchars($selectedPatient['name'])?></div>
        <div class="pt-hdr-meta"><?= htmlspecialchars($selectedPatient['email'])?> · Patient ID #<?= $selected_patient_id?></div>
      </div>
      <div class="pt-hdr-right">
        <div class="scan-count"><i class="fa-regular fa-file-lines"></i> <?= count($scans)?> scan<?= count($scans)!== 1? 's' : ''?></div>
      </div>
    </div>

    <?php if (count($scans) === 0):?>
    <div class="empty-state">
      <div class="ico"><i class="fa-regular fa-file-lines"></i></div>
      <h3>No scans yet</h3>
      <p>This patient hasn't uploaded any reports yet.</p>
    </div>

    <?php else:?>

    <!-- Scan tabs -->
    <div class="scan-tabs">
      <?php foreach ($scans as $sc):
        $scSum = $sc['ai_summary']? json_decode($sc['ai_summary'], true) : null;
        $scType = $scSum['report_type']?? 'LAB';
        $isAct = (int)$sc['id'] === (int)$selected_scan_id;
     ?>
      <a class="scan-tab <?= $isAct? 'active' : ''?>"
         href="?patient_id=<?= $selected_patient_id?>&scan_id=<?= $sc['id']?>">
        <span class="scan-tab-date"><?= date('d M Y', strtotime($sc['scanned_at']))?></span>
        <span class="scan-tab-meta">
          <span class="rtype-dot <?= $scType?>"></span>
          <?= $scType?> · <?= $sc['word_count']?? '?'?>w
        </span>
      </a>
      <?php endforeach;?>
    </div>

    <?php if (!$currentScan):?>
    <div class="empty-state"><div class="ico"><i class="fa-solid fa-magnifying-glass"></i></div><h3>Select a scan</h3><p>Click a scan tab above to view the report.</p></div>

    <?php else:?>

    <!-- Stats row -->
    <?php
      $totalTests = count($summary['all_results']?? []);
      $abnormalCount = count(array_filter($summary['all_results']?? [], fn($r) => in_array(strtoupper($r['flag']?? ''), ['HIGH','LOW','ABNORMAL'])));
      $normalCount = $totalTests - $abnormalCount;
   ?>
    <div class="stats-row">
      <div class="stat-box"><div class="stat-lbl">Total Tests</div><div class="stat-val"><?= $totalTests?: '—'?></div></div>
      <div class="stat-box"><div class="stat-lbl">Abnormal</div><div class="stat-val <?= $abnormalCount > 0? 'red' : 'green'?>"><?= $abnormalCount?></div></div>
      <div class="stat-box"><div class="stat-lbl">Normal</div><div class="stat-val green"><?= $normalCount?: ($totalTests? $totalTests : '—')?></div></div>
      <div class="stat-box"><div class="stat-lbl">Scanned</div><div class="stat-val" style="font-size:.75rem;font-weight:700;"><?= date('d M Y', strtotime($currentScan['scanned_at']))?></div></div>
      <div class="stat-box"><div class="stat-lbl">Words</div><div class="stat-val" style="font-size:.85rem;"><?= $currentScan['word_count']?? '?'?></div></div>
    </div>

    <!-- Status banner -->
    <?php if ($summary &&!empty($summary['condition_status'])):
      $st = strtoupper($summary['condition_status']);
      if (!in_array($st, ['CRITICAL','SERIOUS','STABLE'])) $st = 'STABLE';
      $ico = ['CRITICAL'=>'<i class="fa-solid fa-triangle-exclamation"></i>','SERIOUS'=>'<i class="fa-solid fa-circle-exclamation"></i>','STABLE'=>'<i class="fa-solid fa-circle-check"></i>'][$st];
      $ttl = ['CRITICAL'=>'CRITICAL — Immediate Attention Required','SERIOUS'=>'SERIOUS — Important Findings Detected','STABLE'=>'STABLE — Results Within Normal Range'][$st];
      $sub = ['CRITICAL'=>'Life-threatening values detected.','SERIOUS'=>'Significant findings — prompt follow-up needed.','STABLE'=>'Values within or close to normal range.'][$st];
   ?>
    <div class="banner <?= $st?>">
      <div class="banner-ico"><?= $ico?></div>
      <div>
        <div class="banner-title"><?= $ttl?></div>
        <div class="banner-sub"><?= $sub?></div>
      </div>
    </div>
    <?php endif;?>

    <!-- Results card -->
    <div class="card">
      <div class="card-head">
        <div class="card-icon"><?= $reportType==='RADIOLOGY'?'<i class="fa-solid fa-lungs"></i>':($reportType==='GENERAL'?'<i class="fa-solid fa-stethoscope"></i>':'<i class="fa-solid fa-bullseye"></i>')?></div>
        <div style="flex:1">
          <div class="card-title">AI Analysis Report</div>
          <div class="card-sub">Extracted by OCR · Analysed by Gemini AI</div>
        </div>
        <div class="rtype-badge <?= $reportType?>">
          <?php if ($reportType==='RADIOLOGY'):?><i class="fa-solid fa-x-ray"></i> Radiology
          <?php elseif ($reportType==='GENERAL'):?><i class="fa-solid fa-stethoscope"></i> Consultation
          <?php else:?><i class="fa-solid fa-vial"></i> Lab Report<?php endif;?>
        </div>
      </div>
      <div class="card-body">

        <?php if (!empty($summary['all_results'])):?>
        <div class="sec-lbl"><i class="fa-solid fa-receipt"></i> Test Results</div>
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
            $flagRaw = strtoupper(trim($row['flag']?? 'NORMAL'));
            if ($flagRaw === 'HIGH') {
                $fc = 'H'; $rowClass = 'row-high'; $arrow = '▲'; $color = '#fca5a5';
            } elseif ($flagRaw === 'LOW') {
                $fc = 'L'; $rowClass = 'row-low'; $arrow = '▼'; $color = '#7986cb';
            } elseif ($flagRaw === 'ABNORMAL') {
                $fc = 'A'; $rowClass = 'row-abnormal'; $arrow = '⚠'; $color = '#fdba74';
            } else {
                $fc = 'N'; $rowClass = 'row-normal'; $arrow = '✓'; $color = '#b39ddb';
            }
         ?>
          <tr class="<?= $rowClass?>">
            <td class="t-name"><?= htmlspecialchars($row['test']?? '')?></td>
            <td><span style="color:<?= $color?>;font-weight:800;font-family:var(--mono);"><?= htmlspecialchars($row['result']?? '')?></span></td>
            <td><span class="t-range"><?= htmlspecialchars($row['range']?? '')?></span></td>
            <td><span class="flag <?= $fc?>"><?= $arrow?> <?= $flagRaw?: 'NORMAL'?></span></td>
          </tr>
          <?php endforeach;?>
          </tbody>
        </table>
        <div style="margin-top:18px;"></div>
        <?php endif;?>

        <?php if (($reportType === 'RADIOLOGY' || $reportType === 'GENERAL') &&!empty($summary['critical_findings'])):?>
        <div class="sec-lbl"><?= $reportType==='RADIOLOGY'? '<i class="fa-solid fa-x-ray"></i> Key Imaging Findings' : '<i class="fa-solid fa-stethoscope"></i> Key Clinical Findings'?></div>
        <div class="findings-list" style="margin-bottom:18px;">
          <?php foreach ($summary['critical_findings'] as $cf): if (trim($cf)):?>
          <div class="finding-item"><div class="finding-dot"></div><div class="finding-txt"><?= htmlspecialchars($cf)?></div></div>
          <?php endif; endforeach;?>
        </div>
        <?php elseif ($reportType === 'LAB' && empty($summary['all_results'])):?>
        <div class="all-normal">✅ No test result data available for this scan.</div>
        <?php endif;?>

        <?php if (!empty($summary['diagnosis'])):?>
        <div class="sec-lbl"><i class="fa-solid fa-stethoscope"></i> Diagnosis / Impression</div>
        <div class="tags" style="margin-bottom:18px;">
          <?php foreach ($summary['diagnosis'] as $d): if (trim($d)):?>
          <span class="tag <?= $reportType==='LAB'?'dx':'rad'?>"><?= $reportType==='LAB'?'<i class="fa-solid fa-circle-dot"></i>':'<i class="fa-solid fa-x-ray"></i>'?> <?= htmlspecialchars($d)?></span>
          <?php endif; endforeach;?>
        </div>
        <?php endif;?>

        <?php if (!empty($summary['urgent_actions'])):?>
        <div class="sec-lbl"><?= $reportType==='GENERAL'?'<i class="fa-solid fa-clipboard-list"></i> Follow-up & Advice':'<i class="fa-solid fa-truck-medical"></i> Clinical Actions / Recommendations'?></div>
        <div class="act-list">
          <?php foreach ($summary['urgent_actions'] as $i => $a): if (trim($a)):?>
          <div class="act-item">
            <div class="act-num"><?= $i+1?></div>
            <div class="act-txt"><?= htmlspecialchars($a)?></div>
          </div>
          <?php endif; endforeach;?>
        </div>
        <?php endif;?>

      </div>
    </div>

    <!-- OCR Text card -->
    <div class="card">
      <div class="card-head" onclick="toggleOCR()" style="cursor:pointer;">
        <div class="card-icon"><i class="fa-solid fa-microscope"></i></div>
        <div style="flex:1">
          <div class="card-title">Raw OCR Text</div>
          <div class="card-sub">
            <?php if ($reportType==='LAB'):?>HIGH = red · LOW = blue · Normal = purple
            <?php elseif ($reportType==='RADIOLOGY'):?>Critical findings = red · Other = grey
            <?php else:?>Key findings = red · Other = grey<?php endif;?>
          </div>
        </div>
        <div class="ocr-toggle" id="ocrToggle">
          Show <svg width="12" height="12" viewBox="0 0 12 12" fill="none"><path d="M2 4L6 8L10 4" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
        </div>
      </div>
      <div class="ocr-body" id="ocrBody">
        <div class="hl-legend">
          <?php if ($reportType==='LAB'):?>
          <div class="hl-legend-item"><div class="hl-dot abn"></div> High</div>
          <div class="hl-legend-item"><div class="hl-dot low"></div> Low</div>
          <div class="hl-legend-item"><div class="hl-dot norm"></div> Normal</div>
          <?php else:?>
          <div class="hl-legend-item"><div class="hl-dot abn"></div> Critical finding</div>
          <div class="hl-legend-item"><div class="hl-dot rad"></div> Report text</div>
          <?php endif;?>
        </div>
        <div class="hl-results"><?= $highlightedOCR?></div>

        <div style="margin-top:14px;">
          <div class="sec-lbl" style="cursor:pointer;" onclick="toggleRaw()"><i class="fa-regular fa-file-lines"></i> Full Extracted Text &nbsp;<span id="rawArrow">▼</span></div>
          <div id="rawTextBox" style="display:none;margin-top:8px;">
            <div class="raw-text"><?= htmlspecialchars($currentScan['extracted_text']?? '')?></div>
          </div>
        </div>
      </div>
    </div>

    <?php endif; // currentScan?>
    <?php endif; // scans?>
    <?php endif; // selectedPatient?>

  </div><!-- /main -->
</div><!-- /layout -->

<script>
function filterPatients(q) {
  q = q.toLowerCase();
  document.querySelectorAll('.pt-item').forEach(el => {
    el.style.display = el.dataset.name.includes(q)? '' : 'none';
  });
}

function toggleOCR() {
  const body = document.getElementById('ocrBody');
  const toggle = document.getElementById('ocrToggle');
  const open = body.classList.toggle('open');
  toggle.classList.toggle('open', open);
  toggle.firstChild.textContent = open? 'Hide ' : 'Show ';
}

function toggleRaw() {
  const box = document.getElementById('rawTextBox');
  const arrow = document.getElementById('rawArrow');
  const open = box.style.display === 'none';
  box.style.display = open? 'block' : 'none';
  arrow.textContent = open? '▲' : '▼';
}
</script>
</body>
</html>