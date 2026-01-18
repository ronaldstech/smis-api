<?php
//require '../db.php';
function downloadReports($form, $aca_id, $db, $school){

    $read_aca = $db->query("SELECT * FROM academic_years WHERE status = 'active' AND school = '$school'")->fetch_assoc();
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

    $student_marks = [];
    

    // Group marks by student_id
    foreach ($marks as $mark) {
        $student_id = $mark['student'];
        $score = $mark['final'];

        if (!isset($student_marks[$student_id])) {
            $student_marks[$student_id] = [];
        }

        $student_marks[$student_id][] = $score;
    }

    $student_totals = [];
    if (!empty($student_ids)) {
        // Safely implode student IDs for use in SQL IN clause
        $id_list = implode(',', array_map('intval', $student_ids)); // ensure only integers
        $query = "SELECT * FROM total_marks WHERE student IN ($id_list) AND academic = '$aca_id' AND form = '$form'";
        $readMarks = $db->query($query);

        while ($row = $readMarks->fetch_assoc()) {
            $student_totals[$row['student']] = $row['marks'];
        }
    }

    $subjects = [];
    if (!empty($student_ids)) {
       $readSubjects = $db->query("SELECT * FROM subjects ");//WHERE id IN (SELECT subject FROM marks WHERE student IN ($id_list) AND aca_id = '$aca_id')

        while ($row = $readSubjects->fetch_assoc()) {
            $subjects[] = $row;
        }
    }

    function getSubjectName($subject_id, $subjects) {
        foreach ($subjects as $subj) {
            if ($subj['id'] == $subject_id) {
                return $subj['name'];
            }
        }
        return "Subject ID $subject_id";
    }
    
    $pdf = new FPDF();
    $pdf->SetFont('Times', null, 10);
    
    // 3. Fetch subject-teacher assignments
    $subject_teacher_ids = [];
    $teacher_ids = [];

    $readSubjectTeachers = $db->query("SELECT * FROM subject_teachers WHERE aca_id = '$aca_id' AND form = '$form'");
    while ($row = $readSubjectTeachers->fetch_assoc()) {
        $subject_id = $row['subject'];
        $teacher_id = $row['teacher'];
        $subject_teacher_ids[$subject_id] = $teacher_id;
        $teacher_ids[] = $teacher_id;
    }

    // 4. Fetch teacher details
    $teacher_ids = array_unique($teacher_ids);
    $teachers = [];

    if (!empty($teacher_ids)) {
        $id_list = implode(',', array_map('intval', $teacher_ids));
        $readTeachers = $db->query("SELECT * FROM staff WHERE id IN ($id_list)");
        
        while ($row = $readTeachers->fetch_assoc()) {
            $teachers[$row['id']] = $row; // Contains name, etc.
        }
    }
    $subject_scores = [];
    $subject_positions = [];

    // Step 1: Group scores by subject
    foreach ($marks as $mark) {
        $subject_id = $mark['subject'];
        $student_id = $mark['student'];
        $final = $mark['final'];

        if (!isset($subject_scores[$subject_id])) {
            $subject_scores[$subject_id] = [];
        }
        $subject_scores[$subject_id][$student_id] = $final;
    }

    // Step 2: Rank students per subject
    foreach ($subject_scores as $subject_id => $scores) {
        arsort($scores); // Sort descending by score
        $pos = 1;
        foreach ($scores as $student_id => $score) {
            $subject_positions[$subject_id][$student_id] = $pos++;
        }
    }

    // Group marks with subject names by student
    $marks_assoc = [];
    foreach ($marks as $mark){
        $marks_assoc[$mark['subject']] = $mark;
    }
    $marks_by_student = [];
    foreach ($marks as $mark) {
        /*$mark = $marks[$subject['id']] ?? [
            'student_id' => 0,
            'subject' => $subject['id'],
            'final' => '-',
            'assessments' => 0,
            'end_term' => 0,
            'grade' => '-',
            'remark' => '-',
        ];*/
        $student_id = $mark['student'];
        $subject_id = $mark['subject'];
        $subject_name = getSubjectName($subject_id, $subjects);
        $final_score = $mark['final'];

        // Get teacher name for subject
        $teacher_name = 'N/A';
        $subject_id = $mark['subject'];
        if (isset($subject_teacher_ids[$subject_id])) {
            $tid = $subject_teacher_ids[$subject_id];
            $teacher_name = isset($teachers[$tid]) ? $teachers[$tid]['username'] : "ID $tid";
        }
        $position = $subject_positions[$subject_id][$student_id] ?? 'N/A';
    
        $marks_by_student[$student_id][] = [
            'subject_id' => $subject_id,
            'subject' => substr($subject_name, 0,12),
            'final' => $final_score,
            'assessments' => $mark['assessments'],
            'end_term' => round(($mark['end_term']/100)*60, 0),
            'grade' => $mark['grade'],
            'remark' => substr($mark['remark'], 0,10),
            'teacher' => substr($teacher_name, 0,9),
            'position' => $position
        ];
    }

    // Sort student totals descending for ranking
    if($form<=2){
        arsort($student_totals);
    }
    else{
        asort($student_totals);
    }

    $ranks = [];
    $rank = 1;
    $prev_score = null;
    $tied_count = 0;

    foreach ($student_totals as $student_id => $score) {
        if ($score !== $prev_score) {
            // If score is different, rank jumps by the number of tied scores before
            $rank += $tied_count;
            $tied_count = 1; // reset tie counter
        } else {
            // Same score as previous, count the tie
            $tied_count++;
        }

        $ranks[$student_id] = $rank;
        $prev_score = $score;
    }

    // Calculate class average
    $total_marks_sum = array_sum($student_totals);
    $total_students = count($student_totals);
    $class_average = $total_students > 0 ? round($total_marks_sum / $total_students, 2) : 0;
    
    // Generate a page per student

    $read_school_info = $db->query("SELECT * FROM school_info")->fetch_assoc();
    foreach ($students as $student) {
        $id = $student['id'];
        $name = $student['last'] . ' ' . $student['first'] ?? 'Unknown';
        $gender = $student['gender'] ?? 'Unknown';
    
        $pdf->AddPage();
        
        $pdf->SetFont('Times', 'B', 27);
		
		$pdf->Cell(0, 15, $read_school_info['name'], null, null, 'C');
		$pdf->Ln();
		$pdf->Image('../images/coat.png', 20, 20, 20);
		$pdf->SetFont('Times', '', 11);
		$pdf->Cell(0, 4, $read_school_info['address'], null, null, 'C');
		$pdf->Ln();
		$pdf->Cell(0, 5, $read_school_info['phone'], null, null, 'C');
		$pdf->Ln();
		$pdf->SetFont('Arial', 'I', 9);
		$pdf->Cell(0, 5, $read_school_info['motto'], null, null, 'C');
		$pdf->Ln();
		$pdf->SetFont('Times', 'B', 12);
		$pdf->Cell(0, 5, 'PROGRESS REPORT FOR '.$read_aca['name'].' ACADEMIC YEAR TERM '.$read_aca['term'], null, null, 'C');
		$pdf->Ln();

        // Header
        $pdf->SetFont('Times', 'B', 13);
        $pdf->Cell(100, 7, "STUDENT NAME: ".strtoupper($name), null, 0, 'L');
        $pdf->Cell(50, 7, "FORM: ".strtoupper($form), null, 0, 'R');
        $pdf->Ln();
        $pdf->Cell(50, 7, "GENDER: ".strtoupper($gender), null, 0, 'L');
        $pdf->Ln();
        $pdf->SetFont('Times', 'B', 10);
        $pdf->Cell(34, 6, "SUBJECT", 1);
        $pdf->Cell(22, 6, "ASSES(40%)", 1);
        $pdf->Cell(26, 6, "E_TERM(60%)", 1);
        $pdf->Cell(12, 6, "FINAL", 1);
        $pdf->Cell(15, 6, "GRADE", 1);
        $pdf->Cell(20, 6, "POSITION", 1);
        $pdf->Cell(30, 6, "REMARK", 1);
        $pdf->Cell(31, 6, "TEACHER", 1);
        $pdf->Ln();
        $pdf->SetFont('Times', null, 12);
        // Print subject marks
        if (!empty($marks_by_student[$id])) {
            /*
            foreach ($marks_by_student[$id] as $entry) {
                $pdf->Cell(34, 6, strtoupper($entry['subject']), 1);
                $pdf->Cell(22, 6, $entry['assessments'], 1, 0, 'C');
                $pdf->Cell(26, 6, $entry['end_term'], 1, 0, 'C');
                $pdf->Cell(12, 6, $entry['final'], 1, 0, 'C');
                $pdf->Cell(15, 6, strtoupper($entry['grade']), 1, 0, 'C');
                $pdf->Cell(20, 6, $entry['position'], 1, 0, 'C');
                $pdf->Cell(30, 6, strtoupper($entry['remark']), 1, 0, 'C');
                $pdf->Cell(31, 6, strtoupper($entry['teacher']), 1, 0, null);
                $pdf->Ln();
            }*/
            $student_marks = $marks_by_student[$id];
            $assoc = [];
            foreach ($student_marks as $entry){
                $assoc[$entry['subject_id']] = $entry;
            }
            
            foreach ($subjects as $sub){
                $entry = $assoc[$sub['id']] ?? [
                    'subject' => substr($sub['name'],0,12),
                    'assessments' => "-",
                    'end_term' => '-',
                    'final' => '-',
                    'grade' => '-',
                    'position' => '-',
                    'remark' => '-',
                    'teacher' => '-'
                ];
                
                $pdf->Cell(34, 6, strtoupper($entry['subject']), 1);
                $pdf->Cell(22, 6, $entry['assessments'], 1, 0, 'C');
                $pdf->Cell(26, 6, $entry['end_term'], 1, 0, 'C');
                $pdf->Cell(12, 6, $entry['final'], 1, 0, 'C');
                $pdf->Cell(15, 6, strtoupper($entry['grade']), 1, 0, 'C');
                $pdf->Cell(20, 6, $entry['position'], 1, 0, 'C');
                $pdf->Cell(30, 6, strtoupper($entry['remark']), 1, 0, 'C');
                $pdf->Cell(31, 6, strtoupper($entry['teacher']), 1, 0, null);
                $pdf->Ln();
            }
        } else {
            $pdf->Cell(0, 6, "No marks available", 1, 1);
        }
        //$pdf->Ln();
        //$pdf->Cell(0,7,"All subjects: ".json_encode($subjects));
    
        $pdf->Ln(5);
        // Total, Rank, and Class Average
        $total = $student_totals[$id] ?? 0;

		$pdf->SetFont('Times', 'B', 12);
		$pdf->Cell(130, 5, 'TOTAL MARKS(BEST SIX SUBJECTS INCLUDING ENGLISH)');
        if($form>=3){
            $stat = "POINTS";
        }
        else{
            $stat = "MARKS";
        }
		$pdf->Cell(23, 5, $total." ".$stat);
		$pdf->Ln();
		$pdf->Cell(70, 5, 'POSITION IN CLASS:');
		$count_students = $db->query("SELECT * FROM students WHERE form = '$form'")->num_rows;
        $rank = $ranks[$id] ?? 'N/A';
		$pdf->Cell(10, 5, $rank);
		$pdf->Cell(20, 5, 'OUT OF');
		$pdf->Cell(40, 5, $count_students);
        $compulsory_subject_id = 12;
        $has_compulsory_pass = false;

        foreach (!empty($marks_by_student[$id]) && is_array($marks_by_student[$id]) ? $marks_by_student[$id] : [] as $subject) {
            if ($subject['subject_id'] == $compulsory_subject_id && $subject['final'] > 39) {
                $has_compulsory_pass = true;
                break;
            }
        }

        if ($has_compulsory_pass) {
            //echo "Student $student_id passed the compulsory subject.<br>";
            $count_passed_subjects = 0;
            foreach ($marks_by_student[$id] as $subject) {
                if ($subject['final'] > 39) {
                    $count_passed_subjects++;
                }
            }
            if($count_passed_subjects >= 6){
                //$pdf->Cell(10, 5, $count_passed_subjects);
                $pdf->Cell(10, 5, 'PASS');
            }
            else{
                //$pdf->Cell(10, 5, $count_passed_subjects);
                $pdf->Cell(10, 5, 'FAIL');
            }
        } 
        else {
            //echo "Student $student_id failed or is missing the compulsory subject.<br>";
            $pdf->Cell(10, 5, 'FAIL');
        }
		$pdf->Ln();
		$pdf->Cell(200, 7, "FORM TEACHER'S REMARKS:");
		$pdf->Ln();
		$pdf->SetFont('Times', null, 12);
        if($form>=3){
            $level = "senior";
        }
        else{
            $level = "junior";
        }
        $read_comments = $db->query("SELECT * FROM comments WHERE $total BETWEEN min_m AND max_m AND level = '$level' AND commenter = 'teacher'")->fetch_assoc();
        if($read_comments){
            $content = $read_comments['content'];
            if($gender == "male"){
                
                $pdf->MultiCell(0, 4, $read_comments['content']);
            }
            else{
                $patterns = [
                '/\bHe\b/',     // capital He
                '/\bhe\b/',     // lowercase he
                '/\bHis\b/',    // capital His
                '/\bhis\b/',    // lowercase his
                '/\bHim\b/',    // capital Him
                '/\bhim\b/',    // lowercase him
            ];
    
            $replacements = [
                'She',
                'she',
                'Her',
                'her',
                'Her',
                'her',
            ];
    
            $content = preg_replace($patterns, $replacements, $content);
        
                $pdf->MultiCell(0, 4, $content);
            }
        }
		$pdf->SetFont('Times', 'B', 12);
		$pdf->Cell(40, 5, 'FORM TEACHERS: ');
		$pdf->Ln();
		$pdf->Cell(200, 5, "HEAD TEACHER'S REMARKS:");
		$pdf->Ln();
		$pdf->SetFont('Times', null, 12);
        if($form>=3){
            $level = "senior";
        }
        else{
            $level = "junior";
        }
		$read_comments = $db->query("SELECT * FROM comments WHERE $total BETWEEN min_m AND max_m AND level = '$level' AND commenter = 'head'")->fetch_assoc();
        if($read_comments){
            $content = $read_comments['content'];
            if($gender == "male"){
                
                $pdf->MultiCell(0, 4, $read_comments['content']);
            }
            else{
                $patterns = [
                '/\bHe\b/',     // capital He
                '/\bhe\b/',     // lowercase he
                '/\bHis\b/',    // capital His
                '/\bhis\b/',    // lowercase his
                '/\bHim\b/',    // capital Him
                '/\bhim\b/',    // lowercase him
            ];
    
            $replacements = [
                'She',
                'she',
                'Her',
                'her',
                'Her',
                'her',
            ];
    
            $content = preg_replace($patterns, $replacements, $content);
        
                $pdf->MultiCell(0, 4, $content);
            }
        }
		$pdf->SetFont('Times', 'B', 12);
        $read_head = $db->query("SELECT * FROM staff WHERE role = 'head'")->fetch_assoc();
        if($read_head){
            $name_head = strtoupper($read_head['username']);
        }
		$pdf->Cell(100, 10, "HEAD TEACHER: ". $name_head);
		$pdf->Cell(15, 10, 'SIGN: ');
        $x = $pdf->GetX();
        $y = $pdf->GetY();
        $pdf->Image('../images/sign.png', $x, $y, 20);
		$pdf->Ln();
		$pdf->Cell(100, 5, "NEXT TERMS' REQUIREMENTS:");
		$pdf->Ln();
		$pdf->SetFont('Times', null, 12);
		$pdf->MultiCell(0, 5, "Fees K 180000, School uniform, Scientific Calculator, Mathematical Set, 30cm ruler, Mosquito net, torch, toilet papers, bucket.");
		$pdf->SetFont('Times', 'B', 12);
		$pdf->Ln();
		$pdf->SetFont('Times', 'B', 12);
		$pdf->Cell(185, 5, 'INTERPRETATION OF GRADES');
		$pdf->Ln();
		$pdf->SetFont('Times', null, 12);
		if ($form>2) {
			$pdf->MultiCell(185, 3, '1 = [80-100], 2 = [70-79], 3 = [65-69], 4 = [61-64], 5 = [55-60], 6 = [50-54], 7 = [45-49], 8 = [40-44], 9 = [0-39] Fail, - Didnt Write');
			$pdf->Ln();
		}
		else{
			$pdf->MultiCell(185, 3, "A = [85-100], B = [70-84] , C = [55-69] , D = [40-54], F =[0-39], Fail, - = Didn't Write");
			$pdf->Ln();
		}

		$pdf->SetFont('Times', 'B', 12);
		$pdf->Cell(0, 5, 'NOTE: PAYMENT OF FESS THROUGH BANK ACCOUNT', null, null, 'C');
		$pdf->Ln();
		$pdf->SetFont('Times', null, 12);
		$pdf->MultiCell(0, 4, 'You are Being informed that payment of school fees is through school bank account. Remember to write the name of your ward (student) and form and send a copy of the deposit slip to the school as soon as possible.');
		$pdf->Ln();
        $read_bank = $db->query("SELECT * FROM banking WHERE id = 1")->fetch_assoc();
		$pdf->Cell(50, 5, 'ACCOUNT NAME', 1);
		$pdf->Cell(70, 5, $read_bank['account_name'], 1);
		$pdf->Ln();
		$pdf->Cell(50, 5, 'BANK', 1);
		$pdf->Cell(70, 5, $read_bank['bank'], 1);
		$pdf->Ln();
		$pdf->Cell(50, 5, 'ACCOUNT NO:', 1);
		$pdf->Cell(70, 5, $read_bank['account_no'], 1);
		$pdf->Ln();
		$pdf->Cell(50, 5, 'SERVICE CENTRE', 1);
		$pdf->Cell(70, 5, $read_bank['center'], 1);
		$pdf->Ln();
		$pdf->Cell(50, 5, 'BRANCH', 1);
		$pdf->Cell(70, 5, $read_bank['branch'], 1);
		$pdf->Ln();

    }
    
    // Output the PDF
    $pdf->Output('I', 'student_reports_form_'.$form.'.pdf');

    // $pdf = new FPDF();
    // $pdf->AddPage();
    // $pdf->SetFont('Arial','',12);
    // $pdf->Cell(0,10,"Reports",0,1,'C');
    // $pdf->Output();
}

function downloadMarks($form, $aca_id, $db){

    $read_aca = $db->query("SELECT * FROM academic_years WHERE status = 'active'")->fetch_assoc();
    $aca_id = $read_aca['id'];

    // 1. Read students
    $read_students = $db->query("SELECT * FROM students WHERE form = '$form'");
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

    $student_marks = [];
    

    // Group marks by student_id
    foreach ($marks as $mark) {
        $student_id = $mark['student'];
        $score = $mark['final'];

        if (!isset($student_marks[$student_id])) {
            $student_marks[$student_id] = [];
        }

        $student_marks[$student_id][] = $score;
    }

    $student_totals = [];
    if (!empty($student_ids)) {
        // Safely implode student IDs for use in SQL IN clause
        $id_list = implode(',', array_map('intval', $student_ids)); // ensure only integers
        $query = "SELECT * FROM total_marks WHERE student IN ($id_list) AND academic = '$aca_id' AND form = '$form'";
        $readMarks = $db->query($query);

        while ($row = $readMarks->fetch_assoc()) {
            $student_totals[$row['student']] = $row['marks'];
        }
    }

    $subjects = [];
    if (!empty($student_ids)) {
       $readSubjects = $db->query("SELECT * FROM subjects WHERE id IN (SELECT subject FROM marks WHERE student IN ($id_list) AND aca_id = '$aca_id')");

        while ($row = $readSubjects->fetch_assoc()) {
            $subjects[] = $row;
        }
    }

    function getSubjectName($subject_id, $subjects) {
        foreach ($subjects as $subj) {
            if ($subj['id'] == $subject_id) {
                return $subj['name'];
            }
        }
        return "Subject ID $subject_id";
    }
    
    $pdf = new FPDF();
    $pdf->SetFont('Times', null, 10);
    
    // 3. Fetch subject-teacher assignments
    $subject_teacher_ids = [];
    $teacher_ids = [];

    $readSubjectTeachers = $db->query("SELECT * FROM subject_teachers WHERE aca_id = '$aca_id'");
    while ($row = $readSubjectTeachers->fetch_assoc()) {
        $subject_id = $row['subject'];
        $teacher_id = $row['teacher'];
        $subject_teacher_ids[$subject_id] = $teacher_id;
        $teacher_ids[] = $teacher_id;
    }

    // 4. Fetch teacher details
    $teacher_ids = array_unique($teacher_ids);
    $teachers = [];

    if (!empty($teacher_ids)) {
        $id_list = implode(',', array_map('intval', $teacher_ids));
        $readTeachers = $db->query("SELECT * FROM staff WHERE id IN ($id_list)");
        
        while ($row = $readTeachers->fetch_assoc()) {
            $teachers[$row['id']] = $row; // Contains name, etc.
        }
    }
    $subject_scores = [];
    $subject_positions = [];

    // Step 1: Group scores by subject
    foreach ($marks as $mark) {
        $subject_id = $mark['subject'];
        $student_id = $mark['student'];
        $final = $mark['final'];

        if (!isset($subject_scores[$subject_id])) {
            $subject_scores[$subject_id] = [];
        }
        $subject_scores[$subject_id][$student_id] = $final;
    }

    // Step 2: Rank students per subject
    foreach ($subject_scores as $subject_id => $scores) {
        arsort($scores); // Sort descending by score
        $pos = 1;
        foreach ($scores as $student_id => $score) {
            $subject_positions[$subject_id][$student_id] = $pos++;
        }
    }

    // Group marks with subject names by student
    $marks_by_student = [];
    foreach ($marks as $mark) {
        $student_id = $mark['student'];
        $subject_id = $mark['subject'];
        $subject_name = getSubjectName($subject_id, $subjects);
        $final_score = $mark['final'];

        // Get teacher name for subject
        $teacher_name = 'N/A';
        $subject_id = $mark['subject'];
        if (isset($subject_teacher_ids[$subject_id])) {
            $tid = $subject_teacher_ids[$subject_id];
            $teacher_name = isset($teachers[$tid]) ? $teachers[$tid]['username'] : "ID $tid";
        }
        $position = $subject_positions[$subject_id][$student_id] ?? 'N/A';
    
        $marks_by_student[$student_id][] = [
            'subject_id' => $subject_id,
            'subject' => $subject_name,
            'final' => $final_score,
            'assessments' => $mark['assessments'],
            'end_term' => round(($mark['end_term']/100)*60, 0),
            'grade' => $mark['grade'],
            'remark' => $mark['remark'],
            'teacher' => $teacher_name,
            'position' => $position
        ];
    }

    // Sort student totals descending for ranking
    if($form<=2){
        arsort($student_totals);
    }
    else{
        asort($student_totals);
    }

    $ranks = [];
    $rank = 1;
    $prev_score = null;
    $tied_count = 0;

    foreach ($student_totals as $student_id => $score) {
        if ($score !== $prev_score) {
            // If score is different, rank jumps by the number of tied scores before
            $rank += $tied_count;
            $tied_count = 1; // reset tie counter
        } else {
            // Same score as previous, count the tie
            $tied_count++;
        }

        $ranks[$student_id] = $rank;
        $prev_score = $score;
    }

    // Calculate class average
    $total_marks_sum = array_sum($student_totals);
    $total_students = count($student_totals);
    $class_average = $total_students > 0 ? round($total_marks_sum / $total_students, 2) : 0;
    $sub = [];
    $readSub = $db->query("SELECT * FROM subjects WHERE status = 'active'");
    while($row = $readSub->fetch_assoc()){
        $sub[] = $row;
    }
    // Generate a page per student
    $pdf->AddPage('L');
    $pdf->SetFont('Times', 'B', 16);
    $pdf->Cell(0, 6, "FORM $form", 0, 1, 'C');
    $pdf->Cell(0, 6, $read_aca['name']." -- TERM ".$read_aca['term'], 0, 1, 'C');
    $pdf->Cell(0, 6, "SHEET ANALYSIS BY MARKS", 0, 1, 'C');
    $pdf->Ln();
    $pdf->SetFont('Times', 'B', 10);
    $pdf->Cell(12, 6, "RANK", 1);
    $pdf->Cell(50, 6, "STUDENT NAME", 1);
    $pdf->Cell(12, 6, "SEX", 1);
    foreach($sub as $subject){
        $pdf->Cell(12, 6, substr(strtoupper($subject['name']), 0, 3), 1);
    }
    $pdf->Cell(14, 6, "TOTAL", 1);
    $pdf->Cell(22, 6, "REMARK", 1);
    $pdf->Ln();
    foreach ($student_totals as $student_id => $total) {
        foreach ($students as $student) {
            if ($student['id'] == $student_id) {
                $rank = $ranks[$student_id];
                break;
            }
        }
        $id = $student_id;
        $name = $student['last'] . ' ' . $student['first'] ?? 'Unknown';
        $gender = $student['gender'] ?? 'Unknown';
    
        $pdf->SetFont('Times', null, 12);
        // Print subject marks
        $pdf->Cell(12, 6, $rank, 1, 0, 'C');
        $pdf->Cell(50, 6, substr(strtoupper($name), 0, 18), 1);
        $pdf->Cell(12, 6, substr(strtoupper($gender), 0, 1), 1, 0, 'C');
         // Total, Rank, and Class Average
         $total = $student_totals[$id] ?? 0;
         if (!empty($marks_by_student[$id])) {
            foreach ($sub as $subject) {
                $found = false;
        
                foreach ($marks_by_student[$id] as $entry) {
                    if ($entry['subject_id'] == $subject['id']) {
                        $pdf->Cell(12, 6, $entry['final'], 1, 0, 'C');
                        $found = true;
                        break; // No need to check further
                    }
                }
        
                if (!$found) {
                    $pdf->Cell(12, 6, "-", 1, 0, 'C');
                }
            }
        } else {
            // Student has no marks at all
            foreach ($sub as $subject) {
                $pdf->Cell(12, 6, "-", 1, 0, 'C');
            }
        }
        if($form>=3){
            $stat = "POINTS";
        }
        else{
            $stat = "MARKS";
        }
        $pdf->Cell(14, 6, $total, 1, 0, 'C');
        $compulsory_subject_id = 12;
        $has_compulsory_pass = false;

        foreach (!empty($marks_by_student[$id]) && is_array($marks_by_student[$id]) ? $marks_by_student[$id] : [] as $subject) {
            if ($subject['subject_id'] == $compulsory_subject_id && $subject['final'] > 39) {
                $has_compulsory_pass = true;
                break;
            }
        }

        if ($has_compulsory_pass) {
            //echo "Student $student_id passed the compulsory subject.<br>";
            $count_passed_subjects = 0;
            foreach ($marks_by_student[$id] as $subject) {
                if ($subject['final'] > 39) {
                    $count_passed_subjects++;
                }
            }
            if($count_passed_subjects >= 6){
                //$pdf->Cell(10, 5, $count_passed_subjects);
                $pdf->Cell(22, 6, 'PASS', 1, 0, 'C');
            }
            else{
                //$pdf->Cell(10, 5, $count_passed_subjects);
                $pdf->Cell(22, 6, 'FAIL', 1, 0, 'C');
            }
        } 
        else {
            //echo "Student $student_id failed or is missing the compulsory subject.<br>";
            $pdf->Cell(22, 6, 'FAIL', 1, 0, 'C');
        }
        $pdf->Ln();
		
    }
    // Output the PDF
    $pdf->Output('I', 'marks_analysis_form_'.$form.'.pdf');

    // $pdf = new FPDF();
    // $pdf->AddPage();
    // $pdf->SetFont('Arial','',12);
    // $pdf->Cell(0,10,"Reports",0,1,'C');
    // $pdf->Output();
}

function downloadGrades($form, $aca_id, $db){

    $read_aca = $db->query("SELECT * FROM academic_years WHERE status = 'active'")->fetch_assoc();
    $aca_id = $read_aca['id'];

    // 1. Read students
    $read_students = $db->query("SELECT * FROM students WHERE form = '$form'");
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

    $student_marks = [];
    

    // Group marks by student_id
    foreach ($marks as $mark) {
        $student_id = $mark['student'];
        $score = $mark['final'];

        if (!isset($student_marks[$student_id])) {
            $student_marks[$student_id] = [];
        }

        $student_marks[$student_id][] = $score;
    }

    $student_totals = [];
    if (!empty($student_ids)) {
        // Safely implode student IDs for use in SQL IN clause
        $id_list = implode(',', array_map('intval', $student_ids)); // ensure only integers
        $query = "SELECT * FROM total_marks WHERE student IN ($id_list) AND academic = '$aca_id' AND form = '$form'";
        $readMarks = $db->query($query);

        while ($row = $readMarks->fetch_assoc()) {
            $student_totals[$row['student']] = $row['marks'];
        }
    }

    $subjects = [];
    if (!empty($student_ids)) {
       $readSubjects = $db->query("SELECT * FROM subjects WHERE id IN (SELECT subject FROM marks WHERE student IN ($id_list) AND aca_id = '$aca_id')");

        while ($row = $readSubjects->fetch_assoc()) {
            $subjects[] = $row;
        }
    }

    function getSubjectName($subject_id, $subjects) {
        foreach ($subjects as $subj) {
            if ($subj['id'] == $subject_id) {
                return $subj['name'];
            }
        }
        return "Subject ID $subject_id";
    }
    
    $pdf = new FPDF();
    $pdf->SetFont('Times', null, 10);
    
    // 3. Fetch subject-teacher assignments
    $subject_teacher_ids = [];
    $teacher_ids = [];

    $readSubjectTeachers = $db->query("SELECT * FROM subject_teachers WHERE aca_id = '$aca_id'");
    while ($row = $readSubjectTeachers->fetch_assoc()) {
        $subject_id = $row['subject'];
        $teacher_id = $row['teacher'];
        $subject_teacher_ids[$subject_id] = $teacher_id;
        $teacher_ids[] = $teacher_id;
    }

    // 4. Fetch teacher details
    $teacher_ids = array_unique($teacher_ids);
    $teachers = [];

    if (!empty($teacher_ids)) {
        $id_list = implode(',', array_map('intval', $teacher_ids));
        $readTeachers = $db->query("SELECT * FROM staff WHERE id IN ($id_list)");
        
        while ($row = $readTeachers->fetch_assoc()) {
            $teachers[$row['id']] = $row; // Contains name, etc.
        }
    }
    $subject_scores = [];
    $subject_positions = [];

    // Step 1: Group scores by subject
    foreach ($marks as $mark) {
        $subject_id = $mark['subject'];
        $student_id = $mark['student'];
        $final = $mark['final'];

        if (!isset($subject_scores[$subject_id])) {
            $subject_scores[$subject_id] = [];
        }
        $subject_scores[$subject_id][$student_id] = $final;
    }

    // Step 2: Rank students per subject
    foreach ($subject_scores as $subject_id => $scores) {
        arsort($scores); // Sort descending by score
        $pos = 1;
        foreach ($scores as $student_id => $score) {
            $subject_positions[$subject_id][$student_id] = $pos++;
        }
    }

    // Group marks with subject names by student
    $marks_by_student = [];
    foreach ($marks as $mark) {
        $student_id = $mark['student'];
        $subject_id = $mark['subject'];
        $subject_name = getSubjectName($subject_id, $subjects);
        $final_score = $mark['final'];

        // Get teacher name for subject
        $teacher_name = 'N/A';
        $subject_id = $mark['subject'];
        if (isset($subject_teacher_ids[$subject_id])) {
            $tid = $subject_teacher_ids[$subject_id];
            $teacher_name = isset($teachers[$tid]) ? $teachers[$tid]['username'] : "ID $tid";
        }
        $position = $subject_positions[$subject_id][$student_id] ?? 'N/A';
    
        $marks_by_student[$student_id][] = [
            'subject_id' => $subject_id,
            'subject' => $subject_name,
            'final' => $final_score,
            'assessments' => $mark['assessments'],
            'end_term' => round(($mark['end_term']/100)*60, 0),
            'grade' => $mark['grade'],
            'remark' => $mark['remark'],
            'teacher' => $teacher_name,
            'position' => $position
        ];
    }

    // Sort student totals descending for ranking
    if($form<=2){
        arsort($student_totals);
    }
    else{
        asort($student_totals);
    }

    $ranks = [];
    $rank = 1;
    $prev_score = null;
    $tied_count = 0;

    foreach ($student_totals as $student_id => $score) {
        if ($score !== $prev_score) {
            // If score is different, rank jumps by the number of tied scores before
            $rank += $tied_count;
            $tied_count = 1; // reset tie counter
        } else {
            // Same score as previous, count the tie
            $tied_count++;
        }

        $ranks[$student_id] = $rank;
        $prev_score = $score;
    }

    // Calculate class average
    $total_marks_sum = array_sum($student_totals);
    $total_students = count($student_totals);
    $class_average = $total_students > 0 ? round($total_marks_sum / $total_students, 2) : 0;
    $sub = [];
    $readSub = $db->query("SELECT * FROM subjects WHERE status = 'active'");
    while($row = $readSub->fetch_assoc()){
        $sub[] = $row;
    }
    // Generate a page per student
    $pdf->AddPage('L');
    $pdf->SetFont('Times', 'B', 16);
    $pdf->Cell(0, 6, "FORM $form", 0, 1, 'C');
    $pdf->Cell(0, 6, $read_aca['name']." -- TERM ".$read_aca['term'], 0, 1, 'C');
    $pdf->Cell(0, 6, "SHEET ANALYSIS BY GRADES", 0, 1, 'C');
    $pdf->Ln();
    $pdf->SetFont('Times', 'B', 10);
    $pdf->Cell(12, 6, "RANK", 1);
    $pdf->Cell(50, 6, "STUDENT NAME", 1);
    $pdf->Cell(12, 6, "SEX", 1);
    foreach($sub as $subject){
        $pdf->Cell(12, 6, substr(strtoupper($subject['name']), 0, 3), 1);
    }
    $pdf->Cell(14, 6, "TOTAL", 1);
    $pdf->Cell(22, 6, "REMARK", 1);
    $pdf->Ln();
    foreach ($student_totals as $student_id => $total) {
        foreach ($students as $student) {
            if ($student['id'] == $student_id) {
                $rank = $ranks[$student_id];
                break;
            }
        }
        $id = $student_id;
        $name = $student['last'] . ' ' . $student['first'] ?? 'Unknown';
        $gender = $student['gender'] ?? 'Unknown';
    
        $pdf->SetFont('Times', null, 12);
        // Print subject marks
        $pdf->Cell(12, 6, $rank, 1, 0, 'C');
        $pdf->Cell(50, 6, substr(strtoupper($name), 0, 16), 1);
        $pdf->Cell(12, 6, substr(strtoupper($gender), 0, 1), 1, 0, 'C');
         // Total, Rank, and Class Average
         $total = $student_totals[$id] ?? 0;
         if (!empty($marks_by_student[$id])) {
            foreach ($sub as $subject) {
                $found = false;
        
                foreach ($marks_by_student[$id] as $entry) {
                    if ($entry['subject_id'] == $subject['id']) {
                        $pdf->Cell(12, 6, $entry['grade'], 1, 0, 'C');
                        $found = true;
                        break; // No need to check further
                    }
                }
        
                if (!$found) {
                    $pdf->Cell(12, 6, "-", 1, 0, 'C');
                }
            }
        } else {
            // Student has no marks at all
            foreach ($sub as $subject) {
                $pdf->Cell(12, 6, "-", 1, 0, 'C');
            }
        }
        if($form>=3){
            $stat = "POINTS";
        }
        else{
            $stat = "MARKS";
        }
        $pdf->Cell(14, 6, $total, 1, 0, 'C');
        $compulsory_subject_id = 12;
        $has_compulsory_pass = false;

        foreach (!empty($marks_by_student[$id]) && is_array($marks_by_student[$id]) ? $marks_by_student[$id] : [] as $subject) {
            if ($subject['subject_id'] == $compulsory_subject_id && $subject['final'] > 39) {
                $has_compulsory_pass = true;
                break;
            }
        }

        if ($has_compulsory_pass) {
            //echo "Student $student_id passed the compulsory subject.<br>";
            $count_passed_subjects = 0;
            foreach ($marks_by_student[$id] as $subject) {
                if ($subject['final'] > 39) {
                    $count_passed_subjects++;
                }
            }
            if($count_passed_subjects >= 6){
                //$pdf->Cell(10, 5, $count_passed_subjects);
                $pdf->Cell(22, 6, 'PASS', 1, 0, 'C');
            }
            else{
                //$pdf->Cell(10, 5, $count_passed_subjects);
                $pdf->Cell(22, 6, 'FAIL', 1, 0, 'C');
            }
        } 
        else {
            //echo "Student $student_id failed or is missing the compulsory subject.<br>";
            $pdf->Cell(22, 6, 'FAIL', 1, 0, 'C');
        }
        $pdf->Ln();
		
    }
    // Output the PDF
    $pdf->Output('I', 'grade_analysis_form_'.$form.'.pdf');

    // $pdf = new FPDF();
    // $pdf->AddPage();
    // $pdf->SetFont('Arial','',12);
    // $pdf->Cell(0,10,"Reports",0,1,'C');
    // $pdf->Output();
}

