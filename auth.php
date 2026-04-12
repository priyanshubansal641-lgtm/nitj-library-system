<?php

session_start();

function verifyERPSession(): ?array {

    if (isset($_GET['test_user'])) {
        return [
            'name'  => $_GET['test_user'],
            'role'  => $_GET['test_role'] ?? 'student',
            'email' => $_GET['test_user'] . '@nitj.ac.in',
        ];
    }

    return null; // No valid session
}

$user = verifyERPSession();

if (!$user) {

    header('Location: https://v1.nitj.ac.in/erp/login');
    exit;
}

$name = urlencode($user['name']);
$role = $user['role'];

switch ($role) {
    case 'admin':
    case 'staff':
    case 'librarian':
    case 'faculty':

        header("Location: admin-portal.html?user={$name}&role=admin");
        break;

    case 'student':
    default:

        header("Location: student-view.html");
        break;
}
exit;
