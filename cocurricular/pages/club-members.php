<?php
require_once __DIR__ . '/../../../config/config.php';
require_once __DIR__ . '/../../../includes/authentication.php';
require_once __DIR__ . '/../includes/cocurricular-db.php';

requireAuth();
if (getCurrentUserRoleKey() !== 'osa' || !userCanAccessModule('cocurricular')) {
    http_response_code(403);
    exit('Unauthorized');
}

$pageTitle = 'Club Members';
$activeModule = 'cocurricular';
$activePage = 'club-members';
$breadcrumbs = [
    ['label' => 'Co-Curricular', 'url' => BASE_URL . '/modules/cocurricular/index.php'],
    ['label' => 'Club Members', 'url' => null],
];
$members = cocurricularGetApprovedMembers();
$mainDb = db();
foreach ($members as &$member) {
    $member['student_name'] = 'Unknown';
    $member['student_email'] = '';
    if ($mainDb) {
        $statement = $mainDb->prepare('SELECT full_name, email FROM users WHERE id = ? LIMIT 1');
        $statement->execute([(int) $member['user_id']]);
        $student = $statement->fetch();
        if ($student) {
            $member['student_name'] = $student['full_name'] ?? 'Unknown';
            $member['student_email'] = $student['email'] ?? '';
        }
    }
}
unset($member);
require_once __DIR__ . '/../../../includes/breadcrumbs.php';
require_once __DIR__ . '/../../../includes/layout-start.php';
renderBreadcrumbs($breadcrumbs);
?>
<section class="card">
    <div class="card-header"><h1 class="h5 mb-0">Approved Club Members</h1></div>
    <div class="card-body">
        <?php if (!$members): ?>
            <div class="alert alert-info mb-0">No approved club members yet.</div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-hover mb-0">
                    <thead><tr><th>Student</th><th>Student ID</th><th>Email</th><th>Club</th><th>Date Joined</th></tr></thead>
                    <tbody><?php foreach ($members as $member): ?><tr>
                        <td><?= htmlspecialchars($member['student_name']) ?></td>
                        <td><code><?= htmlspecialchars($member['student_id']) ?></code></td>
                        <td><?= htmlspecialchars($member['student_email']) ?></td>
                        <td><?= htmlspecialchars($member['club_name']) ?></td>
                        <td><?= htmlspecialchars(date('M j, Y', strtotime($member['submitted_at']))) ?></td>
                    </tr><?php endforeach; ?></tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</section>
<?php require_once __DIR__ . '/../../../includes/layout-end.php'; ?>
