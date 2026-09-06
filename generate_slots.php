<?php
/**
 * generate_slots.php
 * ------------------
 * Called as a function include — NOT a standalone page.
 * Usage: require_once 'generate_slots.php'; generateSlots($conn, $doctor_id, $date);
 *
 * What it does:
 *  1. Marks all past (< today) unbooked slots as 'expired'
 *  2. For the given doctor + date, checks if slots already exist
 *  3. If not, reads doctor_schedule for that day-of-week and generates up to max_patients slots
 */

/* ---- Timezone-safe time helpers (defined outside function to avoid re-declaration errors) ---- */
function timeToMinutes(string $t): int {
    [$h, $m] = explode(':', $t);
    return (int)$h * 60 + (int)$m;
}
function minutesToTime(int $mins): string {
    return sprintf("%02d:%02d:00", intdiv($mins, 60), $mins % 60);
}

function generateSlots(mysqli $conn, int $doctor_id, string $date): void
{
    /* ---- 1. Expire old unbooked slots for this doctor ---- */
    $conn->query("
        UPDATE appointment_slots
        SET status = 'expired'
        WHERE doctor_id  = $doctor_id
          AND slot_date  < CURDATE()
          AND is_booked  = 0
          AND status     = 'available'
    ");

    /* ---- 2. Check if slots already generated for this doctor + date ---- */
    $check = $conn->prepare("
        SELECT COUNT(*) AS cnt
        FROM appointment_slots
        WHERE doctor_id = ?
          AND slot_date = ?
          AND status IN ('available','booked')
    ");
    $check->bind_param("is", $doctor_id, $date);
    $check->execute();
    $cnt = $check->get_result()->fetch_assoc()['cnt'];
    $check->close();

    if ($cnt > 0) return; // slots already exist, nothing to do

    /* ---- 3. Get doctor schedule for this day of week ---- */
    $day_name = date('l', strtotime($date)); // e.g. "Monday"

    $sched = $conn->prepare("
        SELECT start_time, end_time, max_patients
        FROM doctor_schedule
        WHERE doctor_id    = ?
          AND working_day  = ?
          AND is_available = 1
        LIMIT 1
    ");
    $sched->bind_param("is", $doctor_id, $day_name);
    $sched->execute();
    $schedule = $sched->get_result()->fetch_assoc();
    $sched->close();

    if (!$schedule) return; // doctor doesn't work this day

    /* ---- 3b. Check if doctor has approved leave on this date ---- */
    $leave_check = $conn->prepare("
        SELECT leave_id FROM doctor_leaves
        WHERE doctor_id = ? AND leave_date = ? AND status = 'approved'
        LIMIT 1
    ");
    $leave_check->bind_param("is", $doctor_id, $date);
    $leave_check->execute();
    $on_leave = $leave_check->get_result()->fetch_assoc();
    $leave_check->close();

    if ($on_leave) return; // doctor is on approved leave, generate no slots

    $start_mins  = timeToMinutes($schedule['start_time']); // e.g. 09:00 -> 540
    $end_mins    = timeToMinutes($schedule['end_time']);    // e.g. 15:00 -> 900
    $max         = min((int)$schedule['max_patients'], 30); // hard cap: 30
    $total_mins  = $end_mins - $start_mins;                 // e.g. 360 minutes
    $slot_mins   = (int)floor($total_mins / $max);          // e.g. 360/30 = 12 min each

    if ($slot_mins < 5) $slot_mins = 5; // minimum 5-min slots

    /* ---- 4. Insert slots ---- */
    $insert = $conn->prepare("
        INSERT INTO appointment_slots
            (doctor_id, slot_date, start_time, end_time, status, is_booked)
        VALUES (?, ?, ?, ?, 'available', 0)
    ");

    $current = $start_mins;
    for ($i = 0; $i < $max; $i++) {
        $slot_start = minutesToTime($current);
        $current   += $slot_mins;

        // Don't go past end time
        if ($current > $end_mins) break;

        $slot_end = minutesToTime($current);

        $insert->bind_param("isss", $doctor_id, $date, $slot_start, $slot_end);
        $insert->execute();
    }

    $insert->close();
}