<?php
/**
 * Widen users columns for Registrar student ID login.
 * Visit once in the browser, then delete or ignore.
 */
require_once __DIR__ . '/../includes/auth.php';
requireRole(['admin']);

ensureStudentBorrowingUsersSchema();

$db = getDB();
$cols = $db->query("SELECT COLUMN_NAME, CHARACTER_MAXIMUM_LENGTH, COLUMN_TYPE
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users'
      AND COLUMN_NAME IN ('username','email','first_name','last_name','student_id','department','phone')
    ORDER BY COLUMN_NAME")->fetchAll();

header('Content-Type: text/plain; charset=utf-8');
echo "Student borrowing users schema updated.\n\n";
foreach ($cols as $col) {
    echo $col['COLUMN_NAME'] . ': ' . $col['COLUMN_TYPE'] . "\n";
}
echo "\nDONE\n";
