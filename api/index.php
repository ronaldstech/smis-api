<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");
header("Content-Type: application/json");

// Handle preflight requests
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

session_start();
require '../db.php';
require "../functions.php";
require "../fpdf/fpdf.php";
require "../pdfs/reports.php";

if (isset($_POST['username'], $_POST['password'])) {

    $username = $db->real_escape_string($_POST['username']);
    $password = md5($_POST['password']);

    $read = $db->query(
        "SELECT id, username, acc_type 
         FROM staff 
         WHERE username='$username' AND password='$password' 
         LIMIT 1"
    );

    if ($read && $read->num_rows > 0) {
        $user = $read->fetch_assoc();
        $_SESSION['staff_id'] = $user['id'];

        echo json_encode([
            "status" => true,
            "admin" => $user['acc_type'] === "admin",
            "user" => [
                "id" => $user['id'],
                "username" => $user['username'],
                "acc_type" => $user['acc_type']
            ]
        ]);
    } else {
        echo json_encode([
            "status" => false,
            "message" => "Invalid username or password"
        ]);
    }
} 

elseif(isset($_GET['getUser'])){
    $user = [];
    $staff_id = $_SESSION['staff_id'];
    $read = $db->query("SELECT * FROM staff WHERE id = '$staff_id'");
    if($read->num_rows > 0){
        $user = $read->fetch_assoc();
        $read_d = $db->query("SELECT * FROM districts WHERE id = '{$user['district']}'");
        $user['district_data'] = $read_d->fetch_assoc();
    }
    header("content-type: application/json; charset=utf-8");
    echo json_encode($user);

}

elseif(isset($_POST['username_add'], $_POST['phone_add'], $_POST['acc_type_add'])){
    $read = $db->query("SELECT * FROM staff WHERE username = '".$_POST['username_add']."' AND acc_type = '".$_POST['acc_type_add']."'")->num_rows;
    if($read > 0){
        echo json_encode(["status" => false, "message" => "Username already exists"]);
        exit();
    }
    else{
        $add = db_insert("staff", [
            "username" => $db->real_escape_string($_POST['username_add']),
            "phone" => $db->real_escape_string($_POST['phone_add']),
            "password" => md5("1234"),
            "acc_type" => strtolower($_POST['acc_type_add']),
            "district" => 0,
            "status" => "active"
        ]);

        echo json_encode(["status" => true, "message" => "Success"]);
    }

}

elseif(isset($_GET['getStaff'])){
    // Clear any previous output (like whitespace or warnings)
    if (ob_get_length()) ob_clean(); 

    $read = $db->query("SELECT * FROM staff ORDER BY status ASC, username ASC");
    $data = [];
    
    if ($read) {
        while($row = $read->fetch_assoc()){
            array_push($data, $row);
        }
    }

    header("Content-Type: application/json");
    // Explicitly exit to prevent any trailing whitespace from being sent
    echo json_encode($data);
    exit();
}

elseif (isset($_GET['getStaffA'])) {
    $school = isset($_GET['school_type'])
        ? $db->real_escape_string($_GET['school_type'])
        : null;

    // 1. Get active academic year
    $acaRes = $db->query("SELECT id FROM academic_years WHERE status = 'active' AND school = '$school' LIMIT 1");

    if (!$acaRes || $acaRes->num_rows === 0) {
        http_response_code(500);
        echo json_encode([
            "status" => false,
            "message" => "No active academic year found"
        ]);
        exit;
    }

    $aca_id = $acaRes->fetch_assoc()['id'];

    // 2. Fetch staff with subject count FOR THIS ACADEMIC YEAR
    $staffQuery = "
        SELECT 
            s.id,
            s.username,
            COUNT(st.id) AS subject_count
        FROM staff s
        LEFT JOIN subject_teachers st 
            ON s.id = st.teacher 
            AND st.aca_id = '$aca_id'
        WHERE s.status = 'active'
        GROUP BY s.id
        ORDER BY s.username ASC
    ";

    $staffResult = $db->query($staffQuery);
    $staffs = [];

    if ($staffResult && $staffResult->num_rows > 0) {
        while ($row = $staffResult->fetch_assoc()) {
            $staffs[$row['id']] = [
                "id" => (int) $row['id'],
                "username" => $row['username'],
                "subject_count" => (int) $row['subject_count'],
                "subjects" => []
            ];
        }
    }

    // 3. Fetch subjects WITH NAMES (JOIN subjects table)
    $subjectQuery = "
        SELECT 
            st.teacher,
            st.form,
            sub.id AS subject_id,
            sub.name AS subject_name
        FROM subject_teachers st
        INNER JOIN subjects sub ON sub.id = st.subject
        WHERE st.aca_id = '$aca_id'
        ORDER BY sub.name ASC
    ";

    $subjectResult = $db->query($subjectQuery);

    if ($subjectResult && $subjectResult->num_rows > 0) {
        while ($row = $subjectResult->fetch_assoc()) {
            if (isset($staffs[$row['teacher']])) {
                $staffs[$row['teacher']]['subjects'][] = [
                    "id" => (int) $row['subject_id'],
                    "name" => $row['subject_name'],
                    "form" => (int) $row['form']
                ];
            }
        }
    }

    header("Content-Type: application/json");
    echo json_encode(array_values($staffs));
    exit;
}

elseif (isset($_GET['getSubjectProgress'])) {

    if (!isset($_GET['aca_id'], $_GET['form'], $_GET['subject'])) {
        http_response_code(400);
        echo json_encode([
            "status" => false,
            "message" => "Missing required parameters"
        ]);
        exit;
    }

    $aca_id  = (int) $_GET['aca_id'];
    $form    = (int) $_GET['form'];
    $subject = (int) $_GET['subject'];

    $sql = "
        SELECT
            COUNT(*) AS total_students,
            SUM(
                CASE
                    WHEN assessments > 0
                      OR end_term > 0
                      OR final > 0
                    THEN 1
                    ELSE 0
                END
            ) AS graded_students
        FROM marks
        WHERE aca_id = $aca_id
          AND form = $form
          AND subject = $subject
    ";

    $res = $db->query($sql);

    if (!$res) {
        http_response_code(500);
        echo json_encode([
            "status" => false,
            "message" => "Database error"
        ]);
        exit;
    }

    $row = $res->fetch_assoc();

    $total   = (int) $row['total_students'];
    $graded  = (int) $row['graded_students'];
    $percent = $total > 0 ? round(($graded / $total) * 100) : 0;

    echo json_encode([
        "status" => true,
        "total_students" => $total,
        "graded_students" => $graded,
        "progress" => $percent
    ]);
    exit;
}

elseif (isset($_GET['getStudents'])) {

    $school_type = isset($_GET['school_type'])
        ? $db->real_escape_string($_GET['school_type'])
        : null;

    $where = $school_type ? "WHERE school = '$school_type'" : "";

    $sql = "
        SELECT s.*, st.username AS admin_name
        FROM students s
        LEFT JOIN staff st ON st.id = s.registered_by
        $where
        ORDER BY s.form ASC, s.last ASC, s.first ASC, s.status ASC
    ";

    $read = $db->query($sql);
    $data = [];

    while ($row = $read->fetch_assoc()) {
        $row['time_added'] = date("d M Y h:i:s A", $row['time_added']);
        $row['admin_name'] = $row['admin_name'] ?? 'unknown';
        $data[] = $row;
    }

    header("Content-Type: application/json");
    echo json_encode($data);
    exit();
}


elseif(isset($_POST['staff_id_edit'], $_POST['username_edit'], $_POST['phone_edit'], $_POST['acc_type_edit'])){
    $edit = db_update("staff", [
        "username" => $db->real_escape_string($_POST['username_edit']),
        "phone" => $db->real_escape_string($_POST['phone_edit']),
        "acc_type" => strtolower($_POST['acc_type_edit']),
    ], ["id" => $_POST['staff_id_edit']]);
    echo json_encode(["status" => true, "message" => "Updated successfully"]);
}

elseif(isset($_POST['staff_id'], $_POST['status'])){
    db_update("staff", [
        "status" => $_POST['status']
    ], ["id" => $_POST['staff_id']]);
    echo json_encode(["status" => true, "message" => "Updated successfully"]);
}

elseif(isset($_GET['print_staff'])){
    $pdf = new FPDF();
    $pdf->AddPage();
    $pdf->SetFont('Arial','B',14);
    $pdf->Cell(40,10,'All Staff',0,0,'C');
    $pdf->Ln();
    $pdf->SetFont('Arial','',12);
    $pdf->SetFillColor(255, 0, 0);
    $pdf->SetTextColor(255);
    $pdf->SetDrawColor(128, 0, 0);
    $pdf->Cell(15,6,'No.',1,0,'L', true);
    $pdf->Cell(60,6,'Username',1,0,'L', true);
    $pdf->Cell(40,6,'Phone',1,0,'L', true);
    $pdf->Cell(30,6,'Account Type',1,0,'L', true);
    $pdf->Cell(30,6,'Status',1,0,'L', true);
    $pdf->Ln();
    $no = 1;
    $pdf->SetFillColor(208, 231, 225);
    $pdf->SetTextColor(0);
    $pdf->SetDrawColor(128, 0, 0);
    function fill() : bool {
        global $no;
        if($no % 2 == 0){
            return true;
        } else {
            return false;
        }
    }
    $pdf->SetFont('Arial','',10);
    $read = $db->query("SELECT * FROM staff ORDER BY status ASC, username ASC");
    while($row = $read->fetch_assoc()){
        $pdf->Cell(15,6,$no,1,0,'L', fill());
        $pdf->Cell(60,6,ucfirst($row['username']),1,0,'L', fill());
        $pdf->Cell(40,6,$row['phone'],1,0,'L', fill());
        $pdf->Cell(30,6,ucfirst($row['acc_type']),1,0,'L', fill());
        $pdf->Cell(30,6,ucfirst($row['status']),1,0,'L', fill());
        $pdf->Ln();
        $no++;
    }
    $pdf->Output();
   
}

elseif(isset($_GET['print_students'])){
    $pdf = new FPDF();
    $pdf->AddPage();
    $pdf->SetFont('Arial','B',14);
    $pdf->Cell(40,10,'All Students',0,0,'C');
    $pdf->Ln();
    $pdf->SetFont('Arial','',12);
    $pdf->SetFillColor(255, 0, 0);
    $pdf->SetTextColor(255);
    $pdf->SetDrawColor(128, 0, 0);
    $pdf->Cell(12,6,'No.',1,0,'L', true);
    $pdf->Cell(55,6,'Full Name',1,0,'L', true);
    $pdf->Cell(55,6,'Email',1,0,'L', true);
    $pdf->Cell(30,6,'Reg Number',1,0,'L', true);
    $pdf->Cell(15,6,'Form',1,0,'L', true);
    $pdf->Cell(20,6,'Status',1,0,'L', true);
    $pdf->Ln();
    $no = 1;
    $pdf->SetFillColor(208, 231, 225);
    $pdf->SetTextColor(0);
    $pdf->SetDrawColor(128, 0, 0);
    function fill() : bool {
        global $no;
        if($no % 2 == 0){
            return true;
        } else {
            return false;
        }
    }
    $pdf->SetFont('Arial','',10);
    $read = $db->query("SELECT * FROM students ORDER BY form ASC, last ASC, first ASC");
    while($row = $read->fetch_assoc()){
        $pdf->Cell(12,6,$no,1,0,'L', fill());
        $pdf->Cell(55,6,ucfirst($row['first'])." ".ucfirst($row['middle'])." ".ucfirst($row['last']),1,0,'L', fill());
        $pdf->Cell(55,6,$row['email'],1,0,'L', fill());
        $pdf->Cell(30,6,ucfirst($row['student_reg']),1,0,'L', fill());
        $pdf->Cell(15,6,ucfirst($row['form']),1,0,'L', fill());
        $pdf->Cell(20,6,ucfirst($row['status']),1,0,'L', fill());
        $pdf->Ln();
        $no++;
    }
    $pdf->Output();
   
}

elseif (
    isset(
        $_POST['fname_add'],
        $_POST['lname_add'],
        $_POST['gender_add'],
        $_POST['form_add'],
        $_POST['school']
    )
) {

    $fname  = $db->real_escape_string(trim($_POST['fname_add']));
    $mname  = $db->real_escape_string(trim($_POST['mname_add'] ?? ''));
    $lname  = $db->real_escape_string(trim($_POST['lname_add']));
    $gender = $db->real_escape_string($_POST['gender_add']);
    $form   = $db->real_escape_string($_POST['form_add']);
    $school = $db->real_escape_string($_POST['school']);

    /* ===== STUDENT REG NUMBER ===== */
    $rand = rand(100, 999);
    $reg  = "form{$form}/" . substr(date("Y"), 2, 3) . "/{$rand}";

    /* ===== EMAIL ===== */
    $sub   = strtolower(substr($fname, 0, 1) . $lname);
    $email = "nkk" . substr(date("Y"), 2, 3) . "-{$sub}@nkk-sec.com";

    $insert = db_insert("students", [
        "first"         => $fname,
        "middle"        => $mname,
        "last"          => $lname,
        "gender"        => $gender,
        "student_reg"   => $reg,
        "form"          => $form,
        "email"         => $email,
        "school"        => $school,
        "time_added"    => time(),
        "registered_by" => 11, // TODO: replace with session user ID
        "status"        => "active"
    ]);

    echo json_encode([
        "status"  => (bool)$insert,
        "message" => $insert
            ? "Student added successfully"
            : "Failed to add student"
    ]);
    exit();
}

elseif (isset($_POST['editStudent'])) {

    $update = db_update("students", [
        "first"       => $db->real_escape_string($_POST['first']),
        "middle"      => $db->real_escape_string($_POST['middle']),
        "last"        => $db->real_escape_string($_POST['last']),
        "gender"      => $db->real_escape_string($_POST['gender']),
        "form"        => $db->real_escape_string($_POST['form']),
        "student_reg" => $db->real_escape_string($_POST['student_reg']),
    ], [
        "id" => intval($_POST['student_id'])
    ]);

    echo json_encode([
        "status"  => (bool)$update,
        "message" => "Updated successfully"
    ]);
    exit();
}


elseif (isset($_POST['student_id'], $_POST['status_edit'])) {

    $status = $_POST['status_edit'] === 'active'
        ? 'active'
        : 'inactive';

    db_update("students", [
        "status" => $status
    ], [
        "id" => intval($_POST['student_id'])
    ]);

    echo json_encode([
        "status"  => true,
        "message" => "Status updated successfully"
    ]);
    exit();
}


elseif(isset($_POST['subject_name'], $_POST['root_add'])){
    db_insert("subjects", [
        "name" => $db->real_escape_string($_POST['subject_name']),
        "root" => $db->real_escape_string($_POST['root_add']),
        "added_by" => $_SESSION['staff_id'],
        "status" => "active"
    ]);
    echo json_encode(["status" => true, "message" => "Subject added successfully"]);
}

elseif(isset($_GET['getSubjects'])){
    $read = $db->query("SELECT * FROM subjects ORDER BY name ASC");
    $data = [];
    while($row = $read->fetch_assoc()){
        array_push($data, $row);
    }
    header("Content-Type: application/json");
    echo json_encode($data);
    exit();
}

elseif(isset($_POST['subject_id_edit'], $_POST['subject_name_edit'], $_POST['root_edit'])){
    db_update("subjects", [
        "name" => $db->real_escape_string($_POST['subject_name_edit']),
        "root" => $db->real_escape_string($_POST['root_edit']),
    ], ["id" => $_POST['subject_id_edit']]);
    echo json_encode(["status" => true, "message" => "Updated successfully"]);
}

elseif(isset($_POST['subject_id'], $_POST['status_edit'])){
    db_update("subjects", [
        "status" => $db->real_escape_string($_POST['status_edit']),
    ], ["id" => $_POST['subject_id']]);
    echo json_encode(["status" => true, "message" => "Status updated successfully"]);
}

elseif(isset($_POST['user_id'], $_POST['current_password'], $_POST['new_password'], $_POST['confirm_password'])){
    $read = $db->query("SELECT * FROM staff WHERE id = ".$_POST['user_id']);
    $row = $read->fetch_assoc();
    if($row['password'] == md5($_POST['current_password'])){
        if($_POST['new_password'] == $_POST['confirm_password']){
            db_update("staff", [
                "password" => md5($_POST['new_password'])
            ], ["id" => $_POST['user_id']]);
            echo json_encode(["status" => true, "message" => "Password changed successfully"]);
        }
        else{
            echo json_encode(["status" => false, "message" => "Passwords do not match"]);
        }
    }
    else{
        echo json_encode(["status" => false, "message" => "Current password is incorrect"]);
    }
}

elseif(isset($_POST['user_id'], $_POST['username_edit'])){
    db_update("staff", [
        "username" => $db->real_escape_string($_POST['username_edit'])
    ], ["id" => $_POST['user_id']]);
    echo json_encode(["status" => true, "message" => "Username updated successfully"]);
}

elseif(isset($_POST['user_id'], $_POST['email_edit'])){
    db_update("staff", [
        "email" => $db->real_escape_string($_POST['email_edit'])
    ], ["id" => $_POST['user_id']]);
    echo json_encode(["status" => true, "message" => "Email updated successfully"]);
}

elseif(isset($_POST['user_id'], $_POST['phone_edit'])){
    db_update("staff", [
        "phone" => $db->real_escape_string($_POST['phone_edit'])
    ], ["id" => $_POST['user_id']]);
    echo json_encode(["status" => true, "message" => "Phone updated successfully"]);
}

elseif (isset($_GET['getAcademicYears'])) {

    $school_type = isset($_GET['school_type'])
        ? $db->real_escape_string($_GET['school_type'])
        : null;

    if ($school_type) {
        $read = $db->query("
            SELECT *
            FROM academic_years
            WHERE school = '$school_type'
        ");
    } else {
        // Fallback (should rarely happen)
        $read = $db->query("
            SELECT *
            FROM academic_years
        ");
    }

    $data = [];
    while ($row = $read->fetch_assoc()) {
        $data[] = $row;
    }

    header("Content-Type: application/json");
    echo json_encode($data);
    exit();
}


elseif (
    isset(
        $_POST['academic_year'],
        $_POST['term'],
        $_POST['opening_date'],
        $_POST['closing_date'],
        $_POST['next_term_begins_on'],
        $_POST['fees'],
        $_POST['school_requirements'],
        $_POST['school_type']
    )
) {

    db_insert("academic_years", [
        "name" => $db->real_escape_string($_POST['academic_year']),
        "term" => $db->real_escape_string($_POST['term']),
        "opening_term" => $_POST['opening_date'],
        "closing_term" => $_POST['closing_date'],
        "next_term_begins" => $_POST['next_term_begins_on'],
        "fees" => $_POST['fees'],
        "requirements" => $_POST['school_requirements'],
        "school" => $_POST['school_type'],
        "status" => "inactive"
    ]);

    echo json_encode([
        "status" => true,
        "message" => "Academic year added successfully"
    ]);
    exit;
}

elseif (
    isset(
        $_POST['academic_year_id_edit'],
        $_POST['academic_name_edit'],
        $_POST['term_edit'],
        $_POST['opening_term_edit'],
        $_POST['closing_term_edit'],
        $_POST['next_term_begins_edit'],
        $_POST['fees_edit'],
        $_POST['school_requirements_edit']
    )
) {

    db_update("academic_years", [
        "name" => $_POST['academic_name_edit'],
        "term" => $_POST['term_edit'],
        "opening_term" => $_POST['opening_term_edit'],
        "closing_term" => $_POST['closing_term_edit'],
        "next_term_begins" => $_POST['next_term_begins_edit'],
        "fees" => $_POST['fees_edit'],
        "requirements" => $_POST['school_requirements_edit']
    ], [
        "id" => $_POST['academic_year_id_edit']
    ]);

    echo json_encode([
        "status" => true,
        "message" => "Updated successfully"
    ]);
    exit;
}

elseif (isset($_POST['academic_id'], $_POST['status_edit'], $_POST['school'])) {

    $academicId = (int) $_POST['academic_id'];
    $status     = $_POST['status_edit'];
    $school     = $db->real_escape_string($_POST['school']); // day | open

    if ($status === "active") {

        // Deactivate all other academic years of the SAME school
        $db->query("
            UPDATE academic_years 
            SET status = 'inactive' 
            WHERE school = '$school'
        ");

        // Activate selected academic year
        db_update("academic_years", [
            "status" => "active"
        ], [
            "id" => $academicId
        ]);

        echo json_encode([
            "status" => true,
            "message" => ucfirst($school) . " academic year activated"
        ]);

    } else {

        // Deactivate only this academic year
        db_update("academic_years", [
            "status" => "inactive"
        ], [
            "id" => $academicId
        ]);

        echo json_encode([
            "status" => true,
            "message" => ucfirst($school) . " academic year deactivated"
        ]);
    }

    exit;
}


elseif (isset($_GET['getAcademic'])) {

    $res = $db->query("
        SELECT * 
        FROM academic_years 
        WHERE status='active'
        LIMIT 1
    ");

    $data = $res->num_rows ? $res->fetch_assoc() : [];

    header("Content-Type: application/json");
    echo json_encode($data);
    exit;
}


elseif(isset($_GET['getRowGrades'])){
    $data = [];
    $read = $db->query("SELECT * FROM grading");
    while($row = $read->fetch_assoc()){
        array_push($data, $row);
    }
    header("Content-Type: application/json");
    echo json_encode($data);
    exit();
}

elseif(isset($_POST['min_mark'], $_POST['max_mark'], $_POST['grade'], $_POST['remark'], $_POST['academic_id'], $_POST['level'])){
    db_insert("grading", [
        "level" => $db->real_escape_string($_POST['level']),
        "min_mark" => $db->real_escape_string($_POST['min_mark']),
        "max_mark" => $db->real_escape_string($_POST['max_mark']),
        "grade" => $db->real_escape_string($_POST['grade']),
        "remark" => $db->real_escape_string($_POST['remark']),
        "academic_id" => $db->real_escape_string($_POST['academic_id']),
        "status" => "inactive"
    ]);
    echo json_encode(["status" => true, "message" => "Added successfully"]);
}

elseif(isset($_POST['academic_id'], $_POST['grade_id'], $_POST['level_edit'], $_POST['min_mark_edit'], $_POST['max_mark'], $_POST['grade'], $_POST['remark'])){
    db_update("grading", [
        "level" => $db->real_escape_string($_POST['level_edit']),
        "min_mark" => $db->real_escape_string($_POST['min_mark_edit']),
        "max_mark" => $db->real_escape_string($_POST['max_mark']),
        "grade" => $db->real_escape_string($_POST['grade']),
        "remark" => $db->real_escape_string($_POST['remark'])
    ], ["id" => $_POST['grade_id']]);
    echo json_encode(["status" => true, "message" => "Updated successfully"]);
}

elseif(isset($_POST['grade_id'], $_POST['status'])){
    db_update("grading", [
        "status" => $db->real_escape_string($_POST['status'])
    ], ["id" => $_POST['grade_id']]);
    echo json_encode(["status" => true, "message" => "Status updated successfully"]);
}

elseif(isset($_GET['getInfo'])){
    $data = [];
    $read = $db->query("SELECT * FROM school_info LIMIT 1");
    if($read->num_rows>0){
        $data = $read->fetch_assoc();
    }

    header("Content-Type: application/json");
    echo json_encode($data);
    exit();
}

elseif(isset($_POST['school_name'], $_POST['address'], $_POST['email'], $_POST['phone'], $_POST['motto'], $_POST['vision'], $_POST['mission'])){
    db_update("school_info", [
        "name" => $db->real_escape_string(strtoupper($_POST['school_name'])),
        "address" => $db->real_escape_string(strtoupper($_POST['address'])),
        "email" => $db->real_escape_string(strtoupper($_POST['email'])),
        "phone" => $db->real_escape_string(strtoupper($_POST['phone'])),
        "motto" => $db->real_escape_string(strtoupper($_POST['motto'])),
        "vision" => $db->real_escape_string(strtoupper($_POST['vision'])),
        "mission" => $db->real_escape_string(strtoupper($_POST['mission']))
    ], ["id" => 1]);
    echo json_encode(["status" => true, "message" => "Updated successfully"]);
}

elseif(isset($_GET['getSubs'])){
    $read = $db->query("SELECT * FROM subjects WHERE status = 'active'");
    $data = [];
    while($row = $read->fetch_assoc()){
        array_push($data, $row);
    }
    header("Content-Type: application/json");
    echo json_encode($data);
    exit();
}

elseif(isset($_POST['teacher_id'], $_POST['subject_id'], $_POST['form'], $_POST['academic_id'])){
    $time = time();
    $check = $db->query("SELECT * FROM subject_teachers WHERE subject = '" . $_POST['subject_id'] . "' AND aca_id = '" . $_POST['academic_id'] . "' AND form = '" . $_POST['form'] . "'");
    $sch = $check->num_rows;
    $data = $check->fetch_assoc();
    $name = $db->query("SELECT * FROM staff WHERE id = '" . $data['teacher'] . "'")->fetch_assoc();
    if($sch > 0){
        echo json_encode(["status" => false, "message" => "Subject already assigned to " . $name['username']]);
        exit();
    }
    else{
        $add = db_insert("subject_teachers", [
            "teacher" => $db->real_escape_string($_POST['teacher_id']),
            "subject" => $db->real_escape_string($_POST['subject_id']),
            "form" => $db->real_escape_string($_POST['form']),
            "aca_id" => $db->real_escape_string($_POST['academic_id']),
            "time_added" => $time,
            "admin" => $_SESSION['staff_id']
        ]);
        if($add){
            echo json_encode(["status" => true, "message" => "Added successfully"]);
        }
        else{
            echo json_encode(["status" => false, "message" => "Failed to add"]);
        }
    }
    exit();
}

elseif(isset($_GET['getSubt'], $_GET['academic_id'], $_GET['teacher_id'])){
    $subjects = [];

    $read = $db->query("SELECT * FROM subjects WHERE id IN (SELECT subject FROM subject_teachers WHERE aca_id = '". $_GET['academic_id'] ."' AND teacher = '". $_GET['teacher_id'] ."')");
    while($row = $read->fetch_assoc()){
        $subjects[$row['id']] = $row;
    }
    
    $read = $db->query("SELECT * FROM subject_teachers WHERE aca_id = '". $_GET['academic_id'] ."' AND teacher = '". $_GET['teacher_id'] ."'");
    $data = [];
    while($row = $read->fetch_assoc()){
        $row['subject_data'] = $subjects[$row['subject']];
        array_push($data, $row);
    }
    header("Content-Type: application/json");
    echo json_encode($data);
    exit();
}

elseif(isset($_POST['deleteSubt'], $_POST['id'])){
    db_delete("subject_teachers", ["id" => $_POST['id']]);
    echo json_encode(["status" => true, "message" => "Deleted successfully"]);
    exit();
}

/*elseif (isset($_GET['getPurchasesOld'])) {
    $products = [];

    $read = $db->query("SELECT * FROM products WHERE id IN (SELECT product FROM purchase)");
    while ($r = $read->fetch_assoc()) {
        $products[$r['id']] = $r;
    }

    $rows = [];
    
    $read = $db->query("SELECT * FROM purchase ");
    while ($row = $read->fetch_assoc()) {
        $row['product_data'] = $products[$row['product']];
        $rows[] = $row;
    }
    
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($rows);
} */

elseif(isset($_GET['getSubjectsTeacher'])){
    $data = [];
    $staff_id = $_SESSION['staff_id'];
    $aca = $db->query("SELECT * FROM academic_years WHERE status = 'active'")->fetch_assoc();
    $read = $db->query("SELECT * FROM subject_teachers WHERE teacher = '$staff_id' AND aca_id = '" . $aca['id'] . "'");
    while($row = $read->fetch_assoc()){
        $subject = $row['subject'];
        $aca_id = $row['aca_id'];
        $form = $row['form'];
        $row['subject_data'] = $db->query("SELECT * FROM subjects WHERE id = '" . $subject . "'")->fetch_assoc();
        $row['total_students'] = $db->query("SELECT * FROM students WHERE form = '" . $form . "'")->num_rows;
        $row['registered'] = $db->query("SELECT * FROM marks WHERE subject = '$subject' AND aca_id = '$aca_id' AND form = '$form'")->num_rows;
        $row['academic_data'] = $aca;
        $data[] = $row;
    }  
    header("Content-Type: application/json");
    echo json_encode($data);
    exit();
}

elseif(isset($_GET['getInfoUpload'])){
    $info = [];
    $read = $db->query("SELECT * FROM exam_upload WHERE id = 1");
    if($read->num_rows > 0){
        $info = $read->fetch_assoc();
    }
    
    echo json_encode(['status' => true, 'data' => $info]);
    exit();
}

elseif(isset($_POST['change_upload_status'])){
    db_update("exam_upload", [
        "status" => $db->real_escape_string($_POST['change_upload_status']),
        "time_action" => time()
    ], ["id" => 1]);
    echo json_encode(["status" => true, "message" => "Status updated successfully"]);
    exit();
}

elseif(isset($_GET['getUploadStatus'])){
    $info = [];
    $read = $db->query("SELECT * FROM exam_upload WHERE id = 1 AND status = 'active'");
    if($read->num_rows > 0){
        $info = $read->fetch_assoc();
    }

    echo json_encode(['status' => true, 'data' => $info]);
    exit();
}

elseif (
    isset($_GET['getYourStudents'], $_GET['form'], $_GET['academic_id'], $_GET['subject_id'])
) {

    $form   = (int) $_GET['form'];
    $aca_id = (int) $_GET['academic_id'];
    $sub_id = (int) $_GET['subject_id'];

    $sql = "
        SELECT 
            s.*,
            m.id AS mark_id,
            m.assessments,
            m.end_term,
            m.final,
            m.remark,
            m.time_updated,
            m.grade
        FROM students s
        LEFT JOIN marks m 
            ON m.student = s.id
            AND m.aca_id = ?
            AND m.form = ?
            AND m.subject = ?
        WHERE s.form = ?
        ORDER BY s.last ASC, s.first ASC
    ";

    $stmt = $db->prepare($sql);
    if (!$stmt) {
        http_response_code(500);
        echo json_encode(["error" => $db->error]);
        exit;
    }

    $stmt->bind_param("iiii", $aca_id, $form, $sub_id, $form);
    $stmt->execute();

    $result = $stmt->get_result();

    $data = [];

    while ($row = $result->fetch_assoc()) {

        if ($row['mark_id']) {
            $row['mark'] = [
                "id"           => (int) $row['mark_id'],
                "student"      => (int) $row['id'],
                "aca_id"       => $aca_id,
                "subject"      => $sub_id,
                "form"         => $form,
                "assessments"  => (int) $row['assessments'],
                "end_term"     => (int) $row['end_term'],
                "final"        => (int) $row['final'],
                "remark"       => $row['remark'],
                "time_updated" => $row['time_updated'],
                "grade"        => $row['grade']
            ];
        } else {
            $row['mark'] = null;
        }

        $row['academic_id'] = $aca_id;
        $row['subject']     = $sub_id;

        unset(
            $row['mark_id'],
            $row['assessments'],
            $row['end_term'],
            $row['final'],
            $row['remark'],
            $row['time_updated'],
            $row['grade']
        );

        $data[] = $row;
    }

    header("Content-Type: application/json; charset=UTF-8");
    echo json_encode($data);
    exit;
}


elseif(isset($_POST['updateAssessment'], $_POST['id'], $_POST['subject'], $_POST['form'], $_POST['academic_id'], $_POST['value'])){
    $student = $_POST['id'];
    $subject = $_POST['subject'];
    $form = $_POST['form'];
    $aca_id = $_POST['academic_id'];
    $assessment = $_POST['value'];
    if($form>=3){
        $level = 'senior';
    }
    else{
        $level = 'junior';
    }

    //checking if already exists
    $read = $db->query("SELECT * FROM marks WHERE student = '$student' AND subject = '$subject' AND form = '$form' AND aca_id = '$aca_id'");
    if($read->num_rows > 0){
        $data = $read->fetch_assoc();
        $final = $assessment + round(($data['end_term']/100)*60, 2);
        $remark_read = $db->query("SELECT * FROM grading WHERE $final BETWEEN min_mark AND max_mark AND level = '$level'")->fetch_assoc();
        $remark = $remark_read['remark'];
        $grade = $remark_read['grade'];

        $upd = db_update("marks", [
            "assessments" => $assessment,
            "final" => $final,
            "time_updated" => time(),
            "remark" => $remark,
            "grade" => $grade
        ], ["student" => $student, "subject" => $subject, "form" => $form, "aca_id" => $aca_id]);
        if($upd){
            echo json_encode(["status" => true, "message" => "Mark updated successfully"]);
            exit();
        }
        else{
            echo json_encode(["status" => false, "message" => $upd->error]);
            exit();
        }
    }
    else{
        $remark_read = $db->query("SELECT * FROM grading WHERE $assessment BETWEEN min_mark AND max_mark AND level = '$level'")->fetch_assoc();
        $remark = $remark_read['remark'];
        $grade = $remark_read['grade'];
        
        $ins = db_insert("marks", [
            "student" => $student,
            "subject" => $subject,
            "form" => $form,
            "aca_id" => $aca_id,
            "assessments" => $assessment,
            "final" => $assessment,
            "remark" => $remark,
            "grade" => $grade,
            "time_updated" => time()
        ]);
        if($ins){
            echo json_encode(["status" => true, "message" => "Mark added successfully"]);
            exit();
        }
        else{
            echo json_encode(["status" => false, "message" => $ins->error]);
            exit();
        }
    }
    exit();
}

elseif(isset($_POST['updateEndTerm'], $_POST['id'], $_POST['subject'], $_POST['form'], $_POST['academic_id'], $_POST['value'])){
    $student = $_POST['id'];
    $subject = $_POST['subject'];
    $form = $_POST['form'];
    $aca_id = $_POST['academic_id'];
    $end_term = $_POST['value'];
    if($form>=3){
        $level = 'senior';
    }
    else{
        $level = 'junior';
    }

    //checking if already exists
    $read = $db->query("SELECT * FROM marks WHERE student = '$student' AND subject = '$subject' AND form = '$form' AND aca_id = '$aca_id'");
    if($read->num_rows > 0){
        $data = $read->fetch_assoc();
        $final = $data['assessments'] + round(($end_term/100)*60, 0);
        $remark_read = $db->query("SELECT * FROM grading WHERE $final BETWEEN min_mark AND max_mark AND level = '$level'")->fetch_assoc();
        $remark = $remark_read['remark'];
        $grade = $remark_read['grade'];

        $upd = db_update("marks", [
            "end_term" => $end_term,
            "final" => $final,
            "time_updated" => time(),
            "remark" => $remark,
            "grade" => $grade
        ], ["student" => $student, "subject" => $subject, "form" => $form, "aca_id" => $aca_id]);
        if($upd){
            echo json_encode(["status" => true, "message" => "Mark updated successfully"]);
            exit();
        }
        else{
            echo json_encode(["status" => false, "message" => $upd->error]);
            exit();
        }
    }
    else{
        $final = round($end_term/100)*60;
        $remark_read = $db->query("SELECT * FROM grading WHERE $final BETWEEN min_mark AND max_mark AND level = '$level'")->fetch_assoc();
        $remark = $remark_read['remark'];
        $grade = $remark_read['grade'];
        
        $ins = db_insert("marks", [
            "student" => $student,
            "subject" => $subject,
            "form" => $form,
            "aca_id" => $aca_id,
            "end_term" => $end_term,
            "final" => $final,
            "remark" => $remark,
            "grade" => $grade,
            "time_updated" => time()
        ]);
        if($ins){
            echo json_encode(["status" => true, "message" => "Mark added successfully"]);
            exit();
        }
        else{
            echo json_encode(["status" => false, "message" => $ins->error]);
            exit();
        }
    }
    exit();
}

elseif(isset($_GET['print_marks'], $_GET['form'], $_GET['academic_id'], $_GET['subject_id'])){
    $form = $_GET['form'];
    $aca_id = $_GET['academic_id'];
    $sub_id = $_GET['subject_id'];
    $aca_data = $db->query("SELECT * FROM academic_years WHERE id = '$aca_id'")->fetch_assoc();
    $sub_data = $db->query("SELECT * FROM subjects WHERE id = '$sub_id'")->fetch_assoc();

    //pprinting table columns
    $pdf = new FPDF();
    $pdf->AddPage();
    $pdf->SetFont('Arial','B',14);
    $pdf->Cell(100,10,$aca_data['name'].' Term '.$aca_data['term'].' Form '.$form.' '.$sub_data['name'],0,0,'L');
    $pdf->Ln();
    $pdf->SetFont('Arial','',12);
    $pdf->SetFillColor(255, 0, 0);
    $pdf->SetTextColor(255);
    $pdf->SetDrawColor(128, 0, 0);
    $pdf->Cell(12,6,'No.',1,0,'L', true);
    $pdf->Cell(50,6,'Full Name',1,0,'L', true);
    $pdf->Cell(16,6,'ASSES',1,0,'L', true);
    $pdf->Cell(25,6,'END TERM',1,0,'L', true);
    $pdf->Cell(15,6,'FINAL',1,0,'L', true);
    $pdf->Cell(20,6,'GRADE',1,0,'L', true);
    $pdf->Cell(37,6,'REMARK',1,0,'L', true);
    $pdf->Ln();
    $no = 1;
    $pdf->SetFillColor(208, 231, 225);
    $pdf->SetTextColor(0);
    $pdf->SetDrawColor(128, 0, 0);
    function fill() : bool {
        global $no;
        if($no % 2 == 0){
            return true;
        } else {
            return false;
        }
    }

    //array for holding all data from marks table
    $all_data = [];
    $readMarks = $db->query("SELECT * FROM marks WHERE subject = '$sub_id' AND form = '$form' AND aca_id = '$aca_id'");
    while($row = $readMarks->fetch_assoc()){
        array_push($all_data, $row); //pushing all data from marks table
    }

    //sorting data
    usort($all_data, function($a, $b){
        return $b['final'] - $a['final'];
    });

    //assigning ranks
    $rank = 1;
    $prev = null;
    $actual_rank = 1;

    //getting student ids
    $student_ids = array_unique(array_column($all_data, 'student'));

    if (!empty($student_ids)) {
        $placeholders = implode(',', array_fill(0, count($student_ids), '?'));
        $sql = "SELECT id, first, last FROM students WHERE id IN ($placeholders)";
        $stmt = $db->prepare($sql);

        if (!$stmt) {
            die("Prepare failed: " . $db->error); // helpful error message
        }

        // Bind the parameters dynamically
        $types = str_repeat('i', count($student_ids)); // change to 's' if IDs are strings
        $stmt->bind_param($types, ...$student_ids);

        $stmt->execute();
        $result = $stmt->get_result();

        $student_names = [];
        while ($row = $result->fetch_assoc()) {
            $student_names[$row['id']] = strtoupper($row['first']) . ' ' . strtoupper($row['last']);
        }
    }
    $pdf->SetFont('Arial','',10);
    //printing data
    foreach($all_data as $mark){
        if($prev !== null && $mark['final']<$prev){
            $rank = $actual_rank;
        }
        $pdf->Cell(12,6, $rank, 1, 0, 'C', true);
        $pdf->Cell(50,6, $student_names[$mark['student']],1,0,'L', true);
        $pdf->Cell(16,6, $mark['assessments'],1,0,'C', true);
        $pdf->Cell(25,6, round(($mark['end_term']/100)*60),1,0,'C', true);
        $pdf->Cell(15,6, $mark['final'],1,0,'C', true);
        $pdf->Cell(20,6, strtoupper($mark['grade']),1,0,'C', true);
        $pdf->Cell(37,6, strtoupper($mark['remark']),1,0,'C', true);
        $pdf->Ln();
        $prev = $mark['final'];
        $actual_rank++;
    }
    $pdf->Output();
}

elseif(isset($_GET['form_select'], $_GET['type_select'])){
    $form = $_GET['form_select'];
    $type = $_GET['type_select'];
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
            downloadReports($form, $aca_id, $db);
        }
        elseif($type == "marks"){
            downloadMarks($form, $aca_id, $db);
        }
        elseif($type == "grades"){
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
            downloadReports($form, $aca_id, $db);
        }
        elseif($type == "marks"){
            downloadMarks($form, $aca_id, $db);
        }
        elseif($type == "grades"){
            downloadGrades($form, $aca_id, $db);
        }
    }
    
}

elseif (isset($_POST['student_id'], $_POST['deleteStudent'])) {
    $messages = [];
    
    $messages[] = "Student deleted successfully";
    $messages[] = "Student Marks deleted successfully";
    $messages[] = "Total Marks deleted successfully";
    $id = $_POST['student_id'];
    
    $deleteS = $db->query("DELETE FROM marks WHERE student = '$id'");
    if ($deleteS) {
       //echo json_encode(["status" => true, "message" => 'Student Marks deleted successfully']);
    }
    
    $deleteS = $db->query("DELETE FROM total_marks WHERE student = '$id'");
    if ($deleteS) {
       //echo json_encode(["status" => true, "message" => 'Total Marks deleted successfully']);
    }
    
    $deleteS = $db->query("DELETE FROM students WHERE id = '$id'");
    if ($deleteS) {
       //echo json_encode(["status" => true, "message" => 'Student deleted successfully']);
       echo json_encode([
           'status' => true,
           'message' => "Deleted successfully"
        ]);
    }
}

else {
    http_response_code(400);
    echo json_encode([
        "status" => false,
        "message" => "Invalid API request",
        "params" => $_GET
    ]);
    exit();
}


?>