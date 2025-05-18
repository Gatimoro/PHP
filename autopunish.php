<?php
$mysqli = new mysqli('localhost', 'root', 'tarhush', 'cw');
date_default_timezone_set('Africa/Cairo');

if ($mysqli->connect_error) {
    die("Connection failed: " . $mysqli->connect_error);
}

$mysqli->set_charset("utf8");

function add_violation_if_none($mysqli, $user_id) {
    // Check if user has any push_ups available
    $check_pushups_stmt = $mysqli->prepare("
        SELECT push_ups FROM parents WHERE ch_id = ?
    ");
    if (!$check_pushups_stmt) die("Prepare failed (push_ups check): " . $mysqli->error);
    $check_pushups_stmt->bind_param("i", $user_id);
    $check_pushups_stmt->execute();
    $pushups_result = $check_pushups_stmt->get_result();

    if ($row = $pushups_result->fetch_assoc()) {
        $current_pushups = (int)$row['push_ups'];
        if ($current_pushups <= 0) {
            echo "⚠️ User $user_id has 0 push-ups, skipping violation.<br>";
            return false;
        }
    } else {
        echo "❌ User $user_id not found in parents table.<br>";
        return false;
    }

   // Check for existing unpaid violation
    $check_stmt = $mysqli->prepare("
        SELECT v.id 
        FROM violation v
        JOIN rules r ON v.rule_id = r.id
        WHERE r.user_id = ? AND v.date_paid = '0000-00-00 00:00:00'
        LIMIT 1
    ");
    if (!$check_stmt) die("Prepare failed: " . $mysqli->error);
    $check_stmt->bind_param("i", $user_id);
    $check_stmt->execute();
    $result = $check_stmt->get_result();
    if ($result->num_rows > 0) {
        echo "❌ User $user_id already has an unpaid violation.<br>";
        return false;
    }

    // Find or insert rule "Gravity Demands Tribute."
    $rule_stmt = $mysqli->prepare("
        SELECT id FROM rules WHERE user_id = ? AND rule = 'Gravity Demands Tribute.' LIMIT 1
    ");
    if (!$rule_stmt) die("Prepare failed (rule select): " . $mysqli->error);
    $rule_stmt->bind_param("i", $user_id);
    $rule_stmt->execute();
    $rule_result = $rule_stmt->get_result();

    if ($row = $rule_result->fetch_assoc()) {
        $rule_id = $row['id'];
    } else {
        $rule_insert_stmt = $mysqli->prepare("
            INSERT INTO rules 
            (rule, user_id, date_start, date_end, creator_id, payment, bonus, hm_times, max, indulgence, indul_date, ind_discount)
            VALUES ('Gravity Demands Tribute.', ?, NOW(), '0000-00-00 00:00:00', ?, 50, 0, 0, 40, 0, '0000-00-00 00:00:00', 3)
        ");
        if (!$rule_insert_stmt) die("Prepare failed (rule insert): " . $mysqli->error);
        $creator_id = $user_id; // Adjust this if needed
        $rule_insert_stmt->bind_param("ii", $user_id, $creator_id);
        if (!$rule_insert_stmt->execute()) {
            die("❌ Error inserting rule: " . $rule_insert_stmt->error . "<br>");
        }
        $rule_id = $rule_insert_stmt->insert_id;
        echo "📜 Rule 'Gravity Demands Tribute.' created for user $user_id (ID $rule_id).<br>";
    }

    // Insert new violation with money penalty equal to current push_ups
    $now = date("Y-m-d H:i:s");
    $money_penalty = $current_pushups;

    $insert_stmt = $mysqli->prepare("
        INSERT INTO violation 
        (rule_id, date_creation, complaint, date_paid, money, log_action_id, say1, say2, saw)
        VALUES (?, ?, 'Bro/Sis got caught.', '0000-00-00 00:00:00', ?, 0, 0, 0, 0)
    ");
    if (!$insert_stmt) die("Prepare failed (violation insert): " . $mysqli->error);
    $insert_stmt->bind_param("isi", $rule_id, $now, $money_penalty);

    if ($insert_stmt->execute()) {
        echo "✅ New violation added for user $user_id (rule $rule_id). Money: $money_penalty<br>";

        // Increment push_ups
        $update_pushups_stmt = $mysqli->prepare("
            UPDATE parents SET push_ups = push_ups + 1 WHERE ch_id = ?
        ");
        if (!$update_pushups_stmt) die("Prepare failed (update push_ups): " . $mysqli->error);
        $update_pushups_stmt->bind_param("i", $user_id);
        $update_pushups_stmt->execute();

        return true;
    } else {
        echo "❌ Error inserting violation: " . $insert_stmt->error . "<br>";
        return false;
    }
}
	
//Iterate through users and evaluate task status
$result = $mysqli->query("SELECT DISTINCT ch_id FROM parents WHERE ch_id IS NOT NULL");
$now = new DateTime();

while ($row = $result->fetch_assoc()) {
    $user_id = $row['ch_id'];

    // Check for unfinished task
    $unfinished = $mysqli->prepare("
        SELECT id FROM log_action 
        WHERE user_id = ? AND time_end = '0000-00-00 00:00:00' 
        LIMIT 1
    ");
    $unfinished->bind_param("i", $user_id);
    $unfinished->execute();
    $unres = $unfinished->get_result();

    if ($unres->num_rows > 0) {
        echo "User $user_id has unfinished task.<br>";
        continue;
    }

    // Check last finished task
    $last_task = $mysqli->prepare("
        SELECT time_end FROM log_action 
        WHERE user_id = ? 
        ORDER BY time_end DESC 
        LIMIT 1
    ");
    $last_task->bind_param("i", $user_id);
    $last_task->execute();
    $last_res = $last_task->get_result();

    if ($last_row = $last_res->fetch_assoc()) {
        $last_end = new DateTime($last_row['time_end']);
        $diff = $now->getTimestamp() - $last_end->getTimestamp();
        if ($diff > 180) { // 180 seconds = 3 minutes
            add_violation_if_none($mysqli, $user_id);
        } else {
            echo "✅ User $user_id finished task recently ($diff seconds ago).<br>";
        }
    } else {
        echo "❌ User $user_id has no finished tasks at all.<br>";
    }
}
?>
