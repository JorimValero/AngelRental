<?php

require_once __DIR__ . '/../auth/helpers.php';
require_authenticated_role('admin');

$connection = get_database_connection();
$error = '';
$users = [];
$staffForm = [
    'full_name' => '',
    'email' => '',
    'username' => '',
];
$editId = filter_var($_GET['edit'] ?? null, FILTER_VALIDATE_INT, [
    'options' => ['min_range' => 1],
]);
$editingStaff = $editId !== false && $editId !== null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) ($_POST['action'] ?? '');
    $userId = filter_var($_POST['user_id'] ?? null, FILTER_VALIDATE_INT, [
        'options' => ['min_range' => 1],
    ]);

    if ($action === 'edit_staff') {
        $editId = $userId;
        $editingStaff = $userId !== false;
    }

    if (!valid_csrf_token($_POST['csrf_token'] ?? null)) {
        $error = 'Your session expired. Please refresh the page and try again.';
    } elseif (in_array($action, ['create_staff', 'edit_staff'], true)) {
        $staffForm = [
            'full_name' => trim((string) ($_POST['full_name'] ?? '')),
            'email' => strtolower(trim((string) ($_POST['email'] ?? ''))),
            'username' => strtolower(trim((string) ($_POST['username'] ?? ''))),
        ];
        $password = (string) ($_POST['password'] ?? '');
        $isCreating = $action === 'create_staff';
        $passwordIsValid = $isCreating
            ? strlen($password) >= 8 && strlen($password) <= 256
            : ($password === '' || (strlen($password) >= 8 && strlen($password) <= 256));

        if (
            $staffForm['full_name'] === ''
            || strlen($staffForm['full_name']) > 100
            || strlen($staffForm['email']) > 254
            || filter_var($staffForm['email'], FILTER_VALIDATE_EMAIL) === false
            || !preg_match('/\A[a-z0-9][a-z0-9_.-]{2,29}\z/', $staffForm['username'])
            || !$passwordIsValid
            || (!$isCreating && $userId === false)
        ) {
            $error = $isCreating
                ? 'Enter a name, a valid email address, a username with 3–30 valid characters, and a password of at least 8 characters.'
                : 'Enter a name, a valid email address, a username with 3–30 valid characters, and leave the password blank or enter at least 8 characters.';
        } else {
            try {
                if ($isCreating) {
                    $statement = $connection->prepare(
                        'INSERT INTO users (full_name, username, email, password_hash, role)
                         VALUES (:full_name, :username, :email, :password_hash, :role)'
                    );
                    $statement->execute([
                        'full_name' => $staffForm['full_name'],
                        'username' => $staffForm['username'],
                        'email' => $staffForm['email'],
                        'password_hash' => password_hash($password, PASSWORD_DEFAULT),
                        'role' => 'staff',
                    ]);
                } else {
                    $staffStatement = $connection->prepare(
                        "SELECT id FROM users WHERE id = :user_id AND role = 'staff'"
                    );
                    $staffStatement->execute(['user_id' => $userId]);
                    if (!$staffStatement->fetchColumn()) {
                        $error = 'That staff account no longer exists.';
                    } else {
                        $sql = 'UPDATE users
                                SET full_name = :full_name, username = :username, email = :email';
                        $parameters = [
                            'full_name' => $staffForm['full_name'],
                            'username' => $staffForm['username'],
                            'email' => $staffForm['email'],
                            'user_id' => $userId,
                        ];
                        if ($password !== '') {
                            $sql .= ', password_hash = :password_hash';
                            $parameters['password_hash'] = password_hash($password, PASSWORD_DEFAULT);
                        }
                        $sql .= " WHERE id = :user_id AND role = 'staff'";
                        $statement = $connection->prepare($sql);
                        $statement->execute($parameters);
                    }
                }

                if ($error === '') {
                    header('Location: manage_users.php');
                    exit;
                }
            } catch (PDOException $exception) {
                if ($exception->getCode() === '23000') {
                    $error = 'That username or email address is already in use.';
                } else {
                    show_database_error($exception);
                    $error = $isCreating
                        ? 'The staff account could not be created.'
                        : 'The staff account could not be updated.';
                }
            }
        }
    } elseif (in_array($action, ['delete_staff', 'delete_customer'], true)) {
        if ($userId === false) {
            $error = 'Select a valid account to delete.';
        } else {
            $expectedRole = $action === 'delete_staff' ? 'staff' : 'customer';
            try {
                $connection->beginTransaction();
                $accountStatement = $connection->prepare(
                    'SELECT id, role FROM users WHERE id = :user_id FOR UPDATE'
                );
                $accountStatement->execute(['user_id' => $userId]);
                $account = $accountStatement->fetch();

                if (!$account || $account['role'] !== $expectedRole) {
                    $connection->rollBack();
                    $error = 'That account no longer exists or cannot be deleted with this action.';
                }

                if ($error === '') {
                    $deleteStatement = $connection->prepare(
                        'DELETE FROM users WHERE id = :user_id AND role = :role'
                    );
                    $deleteStatement->execute([
                        'user_id' => $userId,
                        'role' => $expectedRole,
                    ]);
                    $connection->commit();
                    header('Location: manage_users.php');
                    exit;
                }
            } catch (PDOException $exception) {
                if ($connection->inTransaction()) {
                    $connection->rollBack();
                }
                show_database_error($exception);
                $error = 'The account could not be deleted. Please try again.';
            }
        }
    } else {
        $error = 'That account action is not available.';
    }
}

if ($editingStaff) {
    try {
        $staffStatement = $connection->prepare(
            "SELECT full_name, username, email FROM users WHERE id = :user_id AND role = 'staff'"
        );
        $staffStatement->execute(['user_id' => $editId]);
        $editingStaffAccount = $staffStatement->fetch();
        if (!$editingStaffAccount) {
            $error = $error !== '' ? $error : 'That staff account no longer exists.';
            $editingStaff = false;
        } elseif ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $staffForm = [
                'full_name' => (string) $editingStaffAccount['full_name'],
                'email' => (string) $editingStaffAccount['email'],
                'username' => (string) $editingStaffAccount['username'],
            ];
        }
    } catch (PDOException $exception) {
        show_database_error($exception);
        $error = 'The staff account could not be loaded.';
        $editingStaff = false;
    }
}

try {
    $users = $connection->query(
        "SELECT id, full_name, username, email, contact_number, address, role, created_at
         FROM users
         WHERE role IN ('customer', 'staff')
         ORDER BY role, full_name, id"
    )->fetchAll();
} catch (PDOException $exception) {
    show_database_error($exception);
    $error = 'User accounts could not be loaded.';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Manage accounts | Angels Bridal Rental Shop</title>
    <link rel="stylesheet" href="../css/style.css">
</head>
<body class="storefront account-page">
    <?php require __DIR__ . '/../includes/account_shell_start.php'; ?>
    <main class="admin-inventory">
        <div class="admin-page-heading">
            <div>
                <p class="shop-kicker">Administration</p>
                <h1>Manage accounts</h1>
            </div>
            <p><?= count($users) ?> <?= count($users) === 1 ? 'account' : 'accounts' ?></p>
        </div>

        <?php if ($error !== ''): ?>
            <p class="store-notice store-notice-error" role="alert"><?= escape_html($error) ?></p>
        <?php endif; ?>

        <section class="admin-form-card" aria-labelledby="staff-form-heading">
            <h2 id="staff-form-heading"><?= $editingStaff ? 'Edit staff account' : 'Create a staff account' ?></h2>
            <?php if ($editingStaff): ?>
                <p>Leave the password blank to keep the current password.</p>
            <?php else: ?>
                <p>Staff accounts can view inventory, manage reservations, print product QR tags, and record rental damages.</p>
            <?php endif; ?>
            <form method="post" action="manage_users.php<?= $editingStaff ? '?edit=' . (int) $editId : '' ?>">
                <input type="hidden" name="csrf_token" value="<?= escape_html(csrf_token()) ?>">
                <input type="hidden" name="action" value="<?= $editingStaff ? 'edit_staff' : 'create_staff' ?>">
                <?php if ($editingStaff): ?>
                    <input type="hidden" name="user_id" value="<?= (int) $editId ?>">
                <?php endif; ?>
                <div class="admin-product-form staff-account-form">
                    <div class="field staff-account-email">
                        <label for="email">Email</label>
                        <input id="email" name="email" type="email" maxlength="254" autocomplete="email" value="<?= escape_html($staffForm['email']) ?>" required>
                    </div>
                    <div class="field staff-account-name">
                        <label for="full_name">Name</label>
                        <input id="full_name" name="full_name" type="text" maxlength="100" autocomplete="name" value="<?= escape_html($staffForm['full_name']) ?>" required>
                    </div>
                    <div class="field staff-account-username">
                        <label for="username">Username</label>
                        <input id="username" name="username" type="text" minlength="3" maxlength="30" pattern="[A-Za-z0-9][A-Za-z0-9_.\-]{2,29}" autocomplete="username" value="<?= escape_html($staffForm['username']) ?>" required>
                    </div>
                    <div class="field staff-account-password">
                        <label for="password">Password<?= $editingStaff ? ' (optional)' : '' ?></label>
                        <input id="password" name="password" type="password" minlength="8" maxlength="256" autocomplete="new-password"<?= $editingStaff ? '' : ' required' ?>>
                    </div>
                </div>
                <div class="admin-form-actions">
                    <button class="shop-button" type="submit"><?= $editingStaff ? 'Save staff changes' : 'Create staff account' ?></button>
                    <?php if ($editingStaff): ?>
                        <a class="text-link" href="manage_users.php">Cancel</a>
                    <?php endif; ?>
                </div>
            </form>
        </section>

        <section class="admin-form-card" aria-labelledby="accounts-heading">
            <h2 id="accounts-heading">Existing accounts</h2>
            <p>Customer and staff account details are shown here. Passwords are never displayed.</p>
            <div class="admin-table-wrap">
                <table class="admin-product-table">
                    <thead>
                        <tr>
                            <th scope="col">Name</th>
                            <th scope="col">Username</th>
                            <th scope="col">Role</th>
                            <th scope="col">Email</th>
                            <th scope="col">Contact</th>
                            <th scope="col">Address</th>
                            <th scope="col">Created</th>
                            <th scope="col">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($users as $user): ?>
                            <tr>
                                <td><?= escape_html((string) $user['full_name']) ?></td>
                                <td><?= escape_html((string) $user['username']) ?></td>
                                <td><?= escape_html(ucfirst((string) $user['role'])) ?></td>
                                <td><?= escape_html((string) ($user['email'] ?? '—')) ?></td>
                                <td><?= escape_html((string) ($user['contact_number'] ?? '—')) ?></td>
                                <td><?= escape_html((string) ($user['address'] ?? '—')) ?></td>
                                <td><?= escape_html((string) $user['created_at']) ?></td>
                                <td class="admin-row-actions">
                                    <span class="admin-row-action-links">
                                        <?php if ($user['role'] === 'staff'): ?>
                                            <a class="text-link" href="manage_users.php?edit=<?= (int) $user['id'] ?>">Edit</a>
                                            <form method="post" action="manage_users.php" onsubmit="return confirm('Delete this staff account? This cannot be undone.');">
                                                <input type="hidden" name="csrf_token" value="<?= escape_html(csrf_token()) ?>">
                                                <input type="hidden" name="action" value="delete_staff">
                                                <input type="hidden" name="user_id" value="<?= (int) $user['id'] ?>">
                                                <button class="admin-delete-button" type="submit">Delete</button>
                                            </form>
                                        <?php elseif ($user['role'] === 'customer'): ?>
                                            <form method="post" action="manage_users.php" onsubmit="return confirm('Delete this customer account and its rental/payment history? This cannot be undone.');">
                                                <input type="hidden" name="csrf_token" value="<?= escape_html(csrf_token()) ?>">
                                                <input type="hidden" name="action" value="delete_customer">
                                                <input type="hidden" name="user_id" value="<?= (int) $user['id'] ?>">
                                                <button class="admin-delete-button" type="submit">Delete</button>
                                            </form>
                                        <?php endif; ?>
                                    </span>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if ($users === []): ?>
                            <tr>
                                <td colspan="8">There are no customer or staff accounts to show.</td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </section>
    </main>
    <?php require __DIR__ . '/../includes/account_shell_end.php'; ?>
</body>
</html>
