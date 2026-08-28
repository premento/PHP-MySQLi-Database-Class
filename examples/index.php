<?php
/**
 * Small CRUD demo for MysqliDb.
 *
 * This is example code, not a template to deploy. It has no authentication and no
 * authorisation; serve it on a local development machine only. It is excluded from
 * Composer dist archives via .gitattributes.
 *
 * Expects a `users` table:
 *
 *   CREATE TABLE users (
 *     id INT AUTO_INCREMENT PRIMARY KEY,
 *     login VARCHAR(32) NOT NULL,
 *     customerId INT NOT NULL,
 *     firstName VARCHAR(32) NULL,
 *     lastName VARCHAR(32) NULL,
 *     password TEXT NULL,
 *     createdAt DATETIME NULL,
 *     expires DATETIME NULL
 *   );
 */

require_once(__DIR__ . '/../MysqliDb.php');

error_reporting(E_ALL);
session_start();

$action = 'adddb';
$data = array('id' => '', 'login' => '', 'firstName' => '', 'lastName' => '');

/**
 * Escape a value for HTML output. Every dynamic value below goes through this:
 * interpolating database content straight into markup is an XSS hole.
 */
function h($value)
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/**
 * Per-session CSRF token, checked on every state changing request.
 */
function csrfToken()
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['csrf'];
}

function requireCsrf()
{
    $sent = isset($_REQUEST['csrf']) ? (string) $_REQUEST['csrf'] : '';

    if (!hash_equals(csrfToken(), $sent)) {
        http_response_code(400);
        exit('Invalid CSRF token');
    }
}

function post($key)
{
    return isset($_POST[$key]) ? (string) $_POST[$key] : '';
}

function printUsers()
{
    global $db;

    $users = $db->get('users');
    if ($db->count == 0) {
        echo "<tr><td align='center' colspan='4'>No users found</td></tr>";
        return;
    }

    $csrf = urlencode(csrfToken());

    foreach ($users as $u) {
        $id = urlencode($u['id']);
        echo "<tr>
            <td>" . h($u['id']) . "</td>
            <td>" . h($u['login']) . "</td>
            <td>" . h($u['firstName']) . ' ' . h($u['lastName']) . "</td>
            <td>
                <a href='index.php?action=rm&id={$id}&csrf={$csrf}'>rm</a> ::
                <a href='index.php?action=mod&id={$id}'>ed</a>
            </td>
        </tr>";
    }
}

function action_adddb()
{
    global $db;

    requireCsrf();

    $data = array(
        'login' => post('login'),
        'customerId' => 1,
        'firstName' => post('firstName'),
        'lastName' => post('lastName'),
        'password' => $db->func('SHA1(?)', array(post('password') . 'salt123')),
        'createdAt' => $db->now(),
        'expires' => $db->now('+1Y'),
    );

    $db->insert('users', $data);
    header('Location: index.php');
    exit;
}

function action_moddb()
{
    global $db;

    requireCsrf();

    $data = array(
        'login' => post('login'),
        'customerId' => 1,
        'firstName' => post('firstName'),
        'lastName' => post('lastName'),
    );

    $id = (int) post('id');
    $db->where('customerId', 1);
    $db->where('id', $id);
    $db->update('users', $data);

    header('Location: index.php');
    exit;
}

function action_rm()
{
    global $db;

    requireCsrf();

    $id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
    $db->where('customerId', 1);
    $db->where('id', $id);
    $db->delete('users');

    header('Location: index.php');
    exit;
}

function action_mod()
{
    global $db, $data, $action;

    $action = 'moddb';
    $id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
    $db->where('id', $id);

    $found = $db->getOne('users');
    if ($found) {
        $data = $found + $data;
    }
}

$db = new MysqliDb('localhost', 'root', '', 'testdb');

$requested = isset($_GET['action']) ? (string) $_GET['action'] : '';
// Only dispatch to the handlers defined here; "action_" . $_GET['action'] would
// otherwise reach any global function whose name happens to start with action_.
$allowed = array('adddb', 'moddb', 'rm', 'mod');

if (in_array($requested, $allowed, true)) {
    $handler = 'action_' . $requested;
    $handler();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Users</title>
</head>
<body>

<h3>Users:</h3>
<table width="50%">
    <tr bgcolor="#cccccc">
        <th>ID</th>
        <th>Login</th>
        <th>Name</th>
        <th>Action</th>
    </tr>
    <?php printUsers(); ?>
</table>

<hr width="50%">

<form action="index.php?action=<?php echo h($action); ?>" method="post">
    <input type="hidden" name="csrf" value="<?php echo h(csrfToken()); ?>">
    <input type="hidden" name="id" value="<?php echo h($data['id']); ?>">
    <input type="text" name="login" required placeholder="Login" value="<?php echo h($data['login']); ?>">
    <input type="text" name="firstName" required placeholder="First Name" value="<?php echo h($data['firstName']); ?>">
    <input type="text" name="lastName" required placeholder="Last Name" value="<?php echo h($data['lastName']); ?>">
    <input type="password" name="password" placeholder="Password">
    <input type="submit" value="Save User">
</form>

</body>
</html>
