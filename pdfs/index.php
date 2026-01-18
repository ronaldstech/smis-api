<?php
require '../db.php';
require 'reports.php';


if(isset($_GET['form_select'], $_GET['type_select'], $_GET['school_type'])){
    $form = $db->real_escape_string($_GET['form_select']);
    $type = $_GET['type_select'];
    $school = $db->real_escape_string($_GET['school_type']);
    $read_aca = $db->query("SELECT * FROM academic_years WHERE status = 'active' AND school = '$school'")->fetch_assoc();
    if(!$read_aca){
        echo json_encode(["status" => false, "message" => "No active academic year found"]);
        exit;
    }
    $aca_id = $read_aca['id'];

    // 1. Read students
    $read_students = $db->query("SELECT * FROM students WHERE form = '$form' AND school = '$school'");
    $students = [];
    $student_ids = []; // to collect student IDs

    while ($row = $read_students->fetch_assoc()) {
        $students[] = $row;
        $student_ids[] = $row['id'];
    }

    // 2. Read marks only for those student IDs
    $marks = [];

    if (!empty($student_ids)) {
        // Safely implode student IDs for use in SQL IN clause
        $id_list = implode(',', array_map('intval', $student_ids)); // ensure only integers
        $query = "SELECT * FROM marks WHERE student IN ($id_list) AND aca_id = '$aca_id'";
        $readMarks = $db->query($query);

        while ($row = $readMarks->fetch_assoc()) {
            $marks[] = $row;
        }
    }
    if($form<3){
     
        $compulsory_subject_id = 12;

        // 1. Group marks by student_id and subject_id
        $student_scores = []; // [student_id => [subject_id => score]]

        foreach ($marks as $mark) {
            $student_id = $mark['student'];
            $subject_id = $mark['subject'];
            $score = $mark['final'];

            if (!isset($student_scores[$student_id])) {
                $student_scores[$student_id] = [];
            }

            $student_scores[$student_id][$subject_id] = $score;
        }

        $student_totals = [];

        foreach ($student_scores as $student_id => $subjects) {
            $has_compulsory = array_key_exists($compulsory_subject_id, $subjects);

            // If compulsory subject missing, insert a zero score for it
            if (!$has_compulsory) {
                $subjects[$compulsory_subject_id] = 0;
            }

            // Separate compulsory
            $compulsory_score = $subjects[$compulsory_subject_id];
            unset($subjects[$compulsory_subject_id]);

            // Sort the rest descending and take top 5
            arsort($subjects);
            $top_5 = array_slice($subjects, 0, 5);

            // Add compulsory back
            $best_6 = array_merge([$compulsory_subject_id => $compulsory_score], $top_5);

            // Calculate total
            $total = array_sum($best_6);
            $student_totals[$student_id] = $total;

            // Insert into database (example, adjust as needed)
            $pass = 'PASS';
            foreach ($best_6 as $subject_id => $score) {
                if ($score < 6) { // or your own fail condition
                    $pass = 'FAIL';
                }
            }

            // Save total and pass status (example table `total_marks`)
            $stmt = $db->prepare("INSERT INTO total_marks (student, marks, academic, pass, form)
                                VALUES (?, ?, ?, ?, ?)
                                ON DUPLICATE KEY UPDATE marks = VALUES(marks), pass = VALUES(pass)");
            $stmt->bind_param("iiisi", $student_id, $total, $aca_id, $pass, $form);
            $stmt->execute();
            $stmt->close();
        }


        if($type == "reports"){
            header("Content-Type: application/pdf");
            downloadReports($form, $aca_id, $db, $school);
        }
        elseif($type == "marks"){
            header("Content-Type: application/pdf");
            downloadMarks($form, $aca_id, $db);
        }
        elseif($type == "grades"){
            header("Content-Type: application/pdf");
            downloadGrades($form, $aca_id, $db);
        }
    }
    else{
        $compulsory_subject_id = 12;

        // 1. Group marks by student_id and subject_id
        $student_scores = []; // [student_id => [subject_id => score]]

        foreach ($marks as $mark) {
            $student_id = $mark['student'];
            $subject_id = $mark['subject'];
            $score = $mark['grade'];

            if (!isset($student_scores[$student_id])) {
                $student_scores[$student_id] = [];
            }

            $student_scores[$student_id][$subject_id] = $score;
        }

        $student_totals = [];

        foreach ($student_scores as $student_id => $subjects) {
            $has_compulsory = array_key_exists($compulsory_subject_id, $subjects);

            // If compulsory subject missing, insert a zero score for it
            if (!$has_compulsory) {
                $subjects[$compulsory_subject_id] = 9;
            }

            // Separate compulsory
            $compulsory_score = $subjects[$compulsory_subject_id];
            unset($subjects[$compulsory_subject_id]);

            // Sort the rest descending and take top 5
            asort($subjects); // Sort descending
            $top_5 = array_slice($subjects, 0, 5, true);
            
            // Pad if fewer than 5 subjects
            $needed = 5 - count($top_5);
            for ($i = 0; $i < $needed; $i++) {
                $top_5["padding_$i"] = 9; // use unique keys to avoid collisions
            }

            // Add compulsory back
            $best_6 = array_merge([$compulsory_subject_id => $compulsory_score], $top_5);
            
            foreach ($best_6 as &$sc){
                $sc = (int)$sc;
            }

            // Calculate total
            $total = array_sum($best_6);
            $student_totals[$student_id] = $total;

            // Insert into database (example, adjust as needed)
            $pass = 'PASS';
            foreach ($best_6 as $subject_id => $score) {
                if ($score < 6) { // or your own fail condition
                    $pass = 'FAIL';
                }
            }

            // Save total and pass status (example table `total_marks`)
            $stmt = $db->prepare("INSERT INTO total_marks (student, marks, academic, pass, form)
                                VALUES (?, ?, ?, ?, ?)
                                ON DUPLICATE KEY UPDATE marks = VALUES(marks), pass = VALUES(pass)");
            $stmt->bind_param("iiisi", $student_id, $total, $aca_id, $pass, $form);
            $stmt->execute();
            $stmt->close();
        }


        if($type == "reports"){
            header("Content-Type: application/pdf");
            downloadReports($form, $aca_id, $db, $school);
        }
        elseif($type == "marks"){
            header("Content-Type: application/pdf");
            downloadMarks($form, $aca_id, $db);
        }
        elseif($type == "grades"){
            header("Content-Type: application/pdf");
            downloadGrades($form, $aca_id, $db);
        }
    }
    
}