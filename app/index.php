<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/lib/mailer.php';
require_once __DIR__ . '/policy.php';
require_once __DIR__ . '/consent.php';

// Nota: il gestionale usa una sessione separata (vedi config.php).
// Se qualcuno entra qui con una sessione "platform" non deve creare loop.

if (!vetroom_is_installed()) {
    header('Location: install.php');
    exit;
}

// Logout
if (isset($_GET['action']) && $_GET['action'] === 'logout') {
    vetroom_logout();
    header('Location: index.php');
    exit;
}

$user = vetroom_current_user();

// Se per qualche motivo questa richiesta sta usando una sessione PLATFORM, evita confusione.
if (!$user && function_exists('vetroom_platform_current_user') && vetroom_platform_current_user()) {
    header('Location: platform.php');
    exit;
}
$page = $_GET['page'] ?? 'dashboard';
$db = vetroom_db();
$login_error = null;

// If a SECRETARY/ST...
if ($user) {
    $roleUp0 = strtoupper((string)($user['role'] ?? ''));
    $cid0 = (int)($user['clinic_id'] ?? 0);
    if ($cid0 <= 0 && in_array($roleUp0, ['SECRETARY','STAFF'], true)) {
        if ($page !== 'clinics') {
            header('Location: index.php?page=clinics');
            exit;
        }
    }
}

// Global action: switch active clinic (multi-clinic staff)
if ($user && $_SERVER['REQUEST_METHOD'] === 'POST' && (string)($_POST['form_action'] ?? '') === 'switch_clinic') {
    if (!vr_staff_csrf_is_valid()) {
        $_SESSION['flash'] = ['type'=>'error','msg'=>'Sessione scaduta. Ricarica la pagina e riprova.'];
        header('Location: index.php?page=dashboard');
        exit;
    }

    $newClinicId = (int)($_POST['clinic_id'] ?? 0);
    if ($newClinicId > 0) {
        $st = $db->prepare("SELECT uc.role, c.is_active AS clinic_is_active, c.status AS clinic_status
                            FROM user_clinic uc
                            JOIN clinics c ON c.id = uc.clinic_id
                            WHERE uc.user_id=? AND uc.clinic_id=? AND uc.is_active=1
                            LIMIT 1");
        $st->execute([(int)$user['id'], $newClinicId]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        if ($r) {
            $cActive = (int)($r['clinic_is_active'] ?? 0);
            $cst = strtoupper((string)($r['clinic_status'] ?? ''));
            if ($cActive === 1 && $cst !== 'SUSPENDED' && $cst !== 'REVOKED' && $cst !== 'PENDING') {
                $_SESSION['vetroom_user']['clinic_id'] = $newClinicId;
                $_SESSION['vetroom_user']['role'] = (string)($r['role'] ?? $_SESSION['vetroom_user']['role']);
                $_SESSION['vetroom_user']['role_label'] = vr_staff_role_label(strtoupper((string)($_SESSION['vetroom_user']['role'] ?? '')));
            }
        }
    }

    // Recompute cached user array for this request
    $user = vetroom_current_user();
    header('Location: index.php?page=dashboard');
    exit;
}

// Login
if (!$user && $_SERVER['REQUEST_METHOD'] === 'POST' && ( $_POST['form_action'] ?? '' ) === 'staff_login') {
    if (!vr_staff_csrf_is_valid()) {
        $login_error = 'Sessione scaduta o richiesta non valida. Ricarica la pagina e riprova.';
    } else {
        $email = trim($_POST['email'] ?? '');
        $password = $_POST['password'] ?? '';
        $res = vetroom_login_staff($email, $password);
        if ($res === true) {
            // Optional post-login redirect (used for secure flows like membership activation)
            $next = (string)($_SESSION['vetroom_after_login'] ?? '');
            unset($_SESSION['vetroom_after_login']);
            if ($next !== '' && !str_contains($next, '://') && !str_starts_with($next, '//')) {
                header('Location: ' . $next);
            } else {
                header('Location: index.php');
            }
            exit;
        } else {
            $login_error = $res;
        }
    }
}

// Se non loggato → schermata login
if (!$user) {
    ?>
    <!DOCTYPE html>
    <html lang="it">
    <head>
        <meta charset="utf-8">
        <title><?php echo vr_h(VETROOM_APP_NAME); ?> - Login studio</title>
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <link rel="stylesheet" href="public/styles.css">
    
        <script src="public/ux.js" defer></script>
</head>
    <body>
    <div class="vr-login-wrap">
        <div class="vr-login-card">
            <img src="public/vetroom_logo.png" alt="Logo" class="vr-logo-img">
            <div class="vr-login-title"><?php echo vr_h(VETROOM_APP_NAME); ?> – Area studio</div>
            <div class="vr-login-sub">Accesso riservato al team dello studio.</div>

            <?php if ($login_error): ?>
                <div class="vr-alert vr-alert-error" style="margin-top:12px;"><?php echo vr_h($login_error); ?></div>
            <?php endif; ?>

            <?php if (!empty($_SESSION['vetroom_notice'])): ?>
                <div class="vr-alert vr-alert-success" style="margin-top:12px;">
                    <?php echo vr_h((string)$_SESSION['vetroom_notice']); ?>
                </div>
                <?php unset($_SESSION['vetroom_notice']); ?>
            <?php endif; ?>

            <form method="post" style="margin-top:12px;">
                <input type="hidden" name="form_action" value="staff_login">
                <?php echo vr_staff_csrf_field(); ?>
                <div class="vr-form-row">
                    <label class="vr-label" for="email_vet">Email</label>
                    <input class="vr-input" type="email" id="email_vet" name="email" required>
                </div>
                <div class="vr-form-row">
                    <label class="vr-label" for="password_vet">Password</label>
                    <input class="vr-input" type="password" id="password_vet" name="password" required>
                </div>
                <button type="submit" class="vr-button" style="width:100%;margin-top:6px;">Entra</button>
            </form>
            <p style="margin-top:10px;"><a href="forgot.php">Password dimenticata?</a></p>
        </div>
    </div>
    </body>
    </html>
    <?php
    exit;
}

/* ===== Layout ===== */

function vr_html_header(string $title, string $bodyClass = '', array $bodyData = []): void {
    ?>
    <!DOCTYPE html>
    <html lang="it">
    <head>
        <meta charset="utf-8">
        <title><?php echo vr_h($title); ?></title>
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <link rel="stylesheet" href="public/styles.css">
        <script src="public/ux.js" defer></script>
    </head>
    <body class="<?php echo vr_h(trim('vr-body ' . $bodyClass)); ?>"<?php foreach ($bodyData as $k=>$v) { if ($v === null || $v === '') continue; echo ' data-' . vr_h($k) . '="' . vr_h((string)$v) . '"'; } ?>>
    <?php
}

function vr_html_footer(): void {
    ?>
    </body>
    </html>
    <?php
}

function vr_layout_start(string $title, array $user, string $page): void {
    $clinicId = (int)$user['clinic_id'];
    $header = vr_get_vet_header($clinicId) ?? [];
    if (!is_array($header)) $header = [];

    // Multi-clinic staff: fetch available clinics (used for header switcher + nav).
    $userClinics = [];
    try {
        $dbx = vetroom_db();
        $stC = $dbx->prepare("SELECT c.id, c.name, c.status, c.is_active
                             FROM user_clinic uc
                             JOIN clinics c ON c.id = uc.clinic_id
                             WHERE uc.user_id=? AND uc.is_active=1
                             ORDER BY c.name ASC");
        $stC->execute([(int)$user['id']]);
        $userClinics = $stC->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $t) {
        $userClinics = [];
    }
    $activeClinics = array_values(array_filter($userClinics, function($r) {
        $cActive = (int)($r['is_active'] ?? 0);
        $cst = strtoupper((string)($r['status'] ?? ''));
        return $cActive === 1 && $cst !== 'SUSPENDED' && $cst !== 'REVOKED' && $cst !== 'PENDING';
    }));
    $canSwitchClinic = count($activeClinics) > 1;

    // page-scoped body flags (e.g., disable auto-uppercase on visit pages)
    $noUpper = (str_starts_with($page, 'visit_') || in_array($page, ['visit_new','visit_edit','visit_show'], true));
    $bodyData = [
        'vr-page' => $page,
    ];
    if ($noUpper) $bodyData['vr-no-uppercase'] = '1';

    vr_html_header($title, 'vr-page-' . $page, $bodyData);
    ?>
    <header class="vr-header">
        <div class="vr-header-left">
            <img src="public/vetroom_logo.png" alt="Logo" class="vr-logo-img">
            <div>
                <div class="vr-logo-title"><?php echo vr_h($header['header_name'] ?? VETROOM_APP_NAME); ?></div>
                <div class="vr-logo-sub">
                    <?php
                    // Mobile: we will hide long identifiers (P.IVA / Albo) via CSS classes.
                    $title = trim((string)($header['header_title'] ?? ''));
                    $piva  = trim((string)($header['header_piva'] ?? ''));
                    $albo  = trim((string)($header['header_albo'] ?? ''));

                    if ($title !== '') {
                        echo '<span class="vr-sub-title">' . vr_h($title) . '</span>';
                    }
                    if ($piva !== '') {
                        echo '<span class="vr-sub-piva"> • P.IVA ' . vr_h($piva) . '</span>';
                    }
                    if ($albo !== '') {
                        echo '<span class="vr-sub-albo"> • ' . vr_h($albo) . '</span>';
                    }
                    ?>
                </div>
            </div>
        </div>
        <div class="vr-header-right">
            <?php if ($canSwitchClinic): ?>
                <form method="post" style="display:inline-block;margin-right:10px;vertical-align:middle;">
                    <input type="hidden" name="form_action" value="switch_clinic">
                    <?php echo vr_staff_csrf_field(); ?>
                    <select name="clinic_id" class="vr-input" onchange="this.form.submit();" style="width:auto;max-width:240px;padding:6px 10px;">
                        <?php foreach ($activeClinics as $c):
                            $cid = (int)($c['id'] ?? 0);
                            $cname = (string)($c['name'] ?? ('Clinica #' . $cid));
                            ?>
                            <option value="<?php echo (int)$cid; ?>" <?php echo ($cid === (int)$user['clinic_id']) ? 'selected' : ''; ?>>
                                <?php echo vr_h($cname); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </form>
            <?php endif; ?>
            <span class="vr-header-user"><?php echo vr_h($user['name']); ?> (<?php echo vr_h($user['role_label']); ?>)</span>
            <a class="vr-link" href="index.php?action=logout">Esci</a>
        </div>
    </header>
    <div class="vr-layout">
        <nav class="vr-sidebar" id="vr_sidebar">
            <?php
            // Sidebar is rendered from a data structure so it can be reorganized without hunting HTML.
            // Grouped + collapsible sections reduce the "single long list" feeling.

            $roleUp = strtoupper((string)($user['role'] ?? ''));
            $maxClinics = 1;
            try {
                $stMax = $db->prepare("SELECT COALESCE(max_clinics,1) FROM users WHERE id=? LIMIT 1");
                $stMax->execute([(int)($user['id'] ?? 0)]);
                $maxClinics = max(1, (int)($stMax->fetchColumn() ?: 1));
            } catch (Throwable $t) {
                $maxClinics = (int)($user['max_clinics'] ?? 1);
            }
            $isUnassigned = (((int)($user['clinic_id'] ?? 0)) <= 0 && in_array($roleUp, ['SECRETARY','STAFF'], true));

            if ($isUnassigned) {
                $navSections = [
                    [
                        'key' => 'clinics',
                        'title' => 'Cliniche',
                        'items' => [
                            [
                                'label' => 'Cliniche',
                                'href'  => 'index.php?page=clinics',
                                'active'=> ($page === 'clinics'),
                            ],
                        ],
                    ],
                ];
            } else {
                $navSections = [
                    [
                        'key' => 'studio',
                        'title' => 'Studio',
                        'items' => [
                            [
                                'label' => 'Dashboard',
                                'href'  => 'index.php?page=dashboard',
                                'active'=> ($page === 'dashboard'),
                            ],
                            [
                                'label' => 'Agenda',
                                'href'  => 'index.php?page=appointments',
                                'active'=> ($page === 'appointments'),
                            ],
                        ],
                    ],
                    [
                        'key' => 'anagrafiche',
                        'title' => 'Anagrafiche',
                        'items' => [
                            [
                                'label' => 'Proprietari',
                                'href'  => 'index.php?page=owners',
                                'active'=> in_array($page, ['owners','owner_detail'], true),
                            ],
                            [
                                'label' => 'Animali',
                                'href'  => 'index.php?page=pets',
                                'active'=> in_array($page, ['pets','pet_detail','visit_new','visit_show','visit_edit'], true),
                            ],
                        ],
                    ],
                    [
                        'key' => 'quick',
                        'title' => 'Azioni rapide',
                        'items' => [
                            [
                                'label' => '+ Appuntamento',
                                'href'  => 'index.php?page=appointments#new_appointment',
                                'active'=> false,
                            ],
                            [
                                'label' => '+ Proprietario',
                                'href'  => 'index.php?page=owners#new_owner',
                                'active'=> false,
                            ],
                            [
                                'label' => '+ Animale',
                                'href'  => 'index.php?page=pets#new_pet',
                                'active'=> false,
                            ],
                        ],
                    ],
                    [
                        'key' => 'config',
                        'title' => 'Configurazione',
                        'items' => [
                            [
                                'label' => 'Opzioni',
                                'href'  => 'index.php?page=settings',
                                'active'=> ($page === 'settings'),
                            ],
                        ],
                    ],
                ];

                // Secretary workspace: Clinics page is always available to manage affiliations (membership links/QR).
                if (in_array($roleUp, ['SECRETARY','STAFF'], true)) {
                    $navSections[0]['items'][] = [
                        'label' => 'Cliniche',
                        'href'  => 'index.php?page=clinics',
                        'active'=> ($page === 'clinics'),
                    ];
                }

                // Team management (multi-vet, secretaries) is available to CHIEF only.
                if (vr_policy_is_admin($user)) {
                    $navSections[] = [
                        'key' => 'team',
                        'title' => 'Team',
                        'items' => [
                            [
                                'label' => 'Team',
                                'href'  => 'index.php?page=team',
                                'active'=> ($page === 'team'),
                            ],
                            [
                                'label' => 'Scansiona',
                                'href'  => 'index.php?page=scan_code',
                                'active'=> ($page === 'scan_code'),
                            ],
                        ],
                    ];
                }
            }

            foreach ($navSections as $sec) {
                $secKey = (string)($sec['key'] ?? '');
                $secTitle = (string)($sec['title'] ?? '');
                $items = is_array($sec['items'] ?? null) ? $sec['items'] : [];
                $hasActive = false;
                foreach ($items as $it) {
                    if (!empty($it['active'])) { $hasActive = true; break; }
                }
                ?>
                <div class="vr-nav-group" data-nav-group="<?php echo vr_h($secKey); ?>" data-default-open="<?php echo $hasActive ? '1' : '0'; ?>">
                    <button type="button" class="vr-nav-group-title" data-nav-toggle="<?php echo vr_h($secKey); ?>">
                        <span><?php echo vr_h($secTitle); ?></span>
                        <span class="vr-nav-group-chevron" aria-hidden="true">▾</span>
                    </button>
                    <div class="vr-nav-group-items">
                        <?php foreach ($items as $it):
                            $label = (string)($it['label'] ?? '');
                            $href  = (string)($it['href'] ?? '#');
                            $active = !empty($it['active']);

                            // Mobile/Tablet: icon-only navigation.
                            // Desktop: text-only navigation.
                            $icon = '';
                            switch ($label) {
                                case 'Dashboard':      $icon = 'public/icons/dashboard.png'; break;
                                case 'Agenda':         $icon = 'public/icons/agenda.png'; break;
                                case 'Cliniche':       $icon = 'public/icons/options.png'; break;
                                case 'Proprietari':    $icon = 'public/icons/owner.png'; break;
                                case 'Animali':        $icon = 'public/icons/pets.png'; break;
                                case 'Team':           $icon = 'public/icons/team.png'; break;
                                case 'Scansiona':      $icon = 'public/icons/scan_code.png'; break;
                                case 'Opzioni':        $icon = 'public/icons/options.png'; break;
                                case '+ Appuntamento': $icon = 'public/icons/appointment.png'; break;
                                case '+ Proprietario': $icon = 'public/icons/create_owner.png'; break;
                                case '+ Animale':      $icon = 'public/icons/create_pet.png'; break;
                                default:
                                    $icon = '';
                            }
                            ?>
                            <a href="<?php echo vr_h($href); ?>" class="vr-nav-item<?php echo $active ? ' active' : ''; ?>" title="<?php echo vr_h($label); ?>">
                                <?php if ($icon): ?>
                                  <img class="vr-nav-icon" src="<?php echo vr_h($icon); ?>" alt="" aria-hidden="true">
                                <?php endif; ?>
                                <span class="vr-nav-label"><?php echo vr_h($label); ?></span>
                            </a>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php
            }
            ?>

            <script>
                (function() {
                    const sidebar = document.getElementById('vr_sidebar');
                    if (!sidebar) return;

                    const groups = Array.from(sidebar.querySelectorAll('.vr-nav-group'));

                    function isMobile() {
                        // Mobile/Tablet UX: icon-only toolbar + non-collapsible groups
                        return window.matchMedia && window.matchMedia('(max-width: 1024px)').matches;
                    }

                    function keyFor(groupKey) {
                        return 'vr_sidebar_group_' + groupKey;
                    }

                    function applyState() {
                        const mobile = isMobile();
                        groups.forEach(g => {
                            const groupKey = g.getAttribute('data-nav-group') || '';
                            const defaultOpen = (g.getAttribute('data-default-open') || '0') === '1';
                            const hasActive = !!g.querySelector('.vr-nav-item.active');

                            if (mobile) {
                                g.classList.remove('collapsed');
                                return;
                            }

                            // Active group should stay open.
                            if (hasActive) {
                                g.classList.remove('collapsed');
                                return;
                            }

                            const saved = window.localStorage ? localStorage.getItem(keyFor(groupKey)) : null;
                            if (saved === 'collapsed') {
                                g.classList.add('collapsed');
                            } else if (saved === 'open') {
                                g.classList.remove('collapsed');
                            } else {
                                // First load: open only the active group by default, keep others collapsed except "Azioni rapide".
                                if (!defaultOpen && groupKey !== 'quick') {
                                    g.classList.add('collapsed');
                                }
                            }
                        });
                    }

                    sidebar.addEventListener('click', function(ev) {
                        const btn = ev.target.closest('[data-nav-toggle]');
                        if (!btn) return;
                        if (isMobile()) return;

                        const groupKey = btn.getAttribute('data-nav-toggle') || '';
                        const esc = (window.CSS && typeof CSS.escape === 'function')
                            ? CSS.escape
                            : function(s) { return String(s).replace(/[^a-zA-Z0-9_\-]/g, '\\$&'); };

                        const group = sidebar.querySelector('.vr-nav-group[data-nav-group="' + esc(groupKey) + '"]');
                        if (!group) return;

                        // Don't collapse the group that contains the active page (it would hide where the user is).
                        if (group.querySelector('.vr-nav-item.active')) return;

                        group.classList.toggle('collapsed');
                        const state = group.classList.contains('collapsed') ? 'collapsed' : 'open';
                        if (window.localStorage) {
                            localStorage.setItem(keyFor(groupKey), state);
                        }
                    });

                    window.addEventListener('resize', applyState);
                    applyState();
                })();
            </script>
        </nav>
        <main class="vr-main">
    <?php
}

function vr_layout_end(): void {
    ?>
        </main>
    </div>
    <?php
    vr_html_footer();
}

/* ===== Guard-rails (CSRF + Policy) ===== */

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!vr_staff_csrf_is_valid()) {
        http_response_code(403);
        vr_layout_start('Richiesta non valida - ' . VETROOM_APP_NAME, $user, $page);
        echo '<div class="vr-card"><p style="font-size:13px;margin:0;">Richiesta non valida o sessione scaduta. Ricarica la pagina e riprova.</p></div>';
        vr_layout_end();
        exit;
    }

    $formAction = (string)($_POST['form_action'] ?? '');
    $required = vr_policy_required_capability_for_form_action($formAction);
    if ($required !== '' && !vr_policy_can($required, $user)) {
        http_response_code(403);
        vr_layout_start('Operazione non autorizzata - ' . VETROOM_APP_NAME, $user, $page);
        echo '<div class="vr-card"><p style="font-size:13px;margin:0;">Operazione non autorizzata.</p></div>';
        vr_layout_end();
        exit;
    }
}

/* ===== Router ===== */

$clinicId = (int)$user['clinic_id'];

switch ($page) {

    /* DASHBOARD */
    case 'dashboard':
    default: {
        $today = vr_today_date();

        if (vr_policy_is_admin($user)) {
            $stmtToday = $db->prepare("
                SELECT a.*, p.name AS pet_name, o.name AS owner_name, o.surname AS owner_surname
                FROM appointments a
                JOIN pets p ON a.pet_id = p.id
                JOIN owners o ON a.owner_id = o.id
                WHERE a.clinic_id = ? AND a.date = ?
                ORDER BY a.time
            ");
        } else {
            $stmtToday = $db->prepare("
                SELECT a.*, p.name AS pet_name, o.name AS owner_name, o.surname AS owner_surname
                FROM appointments a
                JOIN pets p ON a.pet_id = p.id
                JOIN owners o ON a.owner_id = o.id
                JOIN vet_owner_relations r ON r.owner_id = o.id AND r.clinic_id = o.clinic_id
                WHERE a.clinic_id = ? AND a.date = ? AND r.status = 'active'
                ORDER BY a.time
            ");
        }
        $stmtToday->execute([$clinicId, $today]);
        $appsToday = $stmtToday->fetchAll(PDO::FETCH_ASSOC);

        if (vr_policy_is_admin($user)) {
            $stmt = $db->prepare("SELECT COUNT(*) FROM owners WHERE clinic_id = ?");
            $stmt->execute([$clinicId]);
            $countOwners = (int)$stmt->fetchColumn();

            $stmt = $db->prepare("SELECT COUNT(*) FROM pets WHERE clinic_id = ?");
            $stmt->execute([$clinicId]);
            $countPets = (int)$stmt->fetchColumn();
        } else {
            $stmt = $db->prepare("SELECT COUNT(*) FROM owners o JOIN vet_owner_relations r ON r.owner_id=o.id AND r.clinic_id=o.clinic_id WHERE o.clinic_id=? AND r.status='active'");
            $stmt->execute([$clinicId]);
            $countOwners = (int)$stmt->fetchColumn();

            $stmt = $db->prepare("SELECT COUNT(*) FROM pets p JOIN owners o ON o.id=p.owner_id JOIN vet_owner_relations r ON r.owner_id=o.id AND r.clinic_id=o.clinic_id WHERE p.clinic_id=? AND r.status='active'");
            $stmt->execute([$clinicId]);
            $countPets = (int)$stmt->fetchColumn();
        }

        vr_layout_start('Dashboard - ' . VETROOM_APP_NAME, $user, 'dashboard');
        ?>
        <h1 class="vr-page-title">Oggi in studio</h1>
        <p class="vr-page-subtitle">Riepilogo rapido della giornata.</p>

        <div class="vr-kpi-row">
            <div class="vr-kpi">
                <div class="vr-kpi-label">Appuntamenti di oggi</div>
                <div class="vr-kpi-value"><?php echo count($appsToday); ?></div>
            </div>
            <div class="vr-kpi">
                <div class="vr-kpi-label">Proprietari</div>
                <div class="vr-kpi-value"><?php echo $countOwners; ?></div>
            </div>
            <div class="vr-kpi">
                <div class="vr-kpi-label">Animali</div>
                <div class="vr-kpi-value"><?php echo $countPets; ?></div>
            </div>
        </div>

        <div class="vr-card">
            <div class="vr-card-header">Agenda di oggi (<?php echo vr_h($today); ?>)</div>
            <?php if (!$appsToday): ?>
                <p style="font-size:13px;margin:0;">Nessun appuntamento per oggi.</p>
            <?php else: ?>
                <table class="vr-table">
                    <thead>
                    <tr>
                        <th>Ora</th>
                        <th>Animale</th>
                        <th>Proprietario</th>
                        <th>Tipo visita</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($appsToday as $app): ?>
                        <tr>
                            <td><?php echo vr_h($app['time']); ?></td>
                            <td><?php echo vr_h($app['pet_name']); ?></td>
                            <td><?php echo vr_h($app['owner_name'] . ' ' . $app['owner_surname']); ?></td>
                            <td><?php echo vr_h($app['type']); ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
        <?php
        vr_layout_end();
        break;
    }

    /* AGENDA */
    case 'appointments': {
        $view = $_GET['view'] ?? 'month';
        if (!in_array($view, ['week','month'], true)) {
            $view = 'month';
        }

        $selectedDate = $_GET['selected_date'] ?? vr_today_date();
        $slotTime = $_GET['slot_time'] ?? '';
        $appointment_error = '';
        $appointment_success = '';

        // Proprietari e animali
        if (vr_policy_is_admin($user)) {
            $stmtOwnersAll = $db->prepare("SELECT * FROM owners WHERE clinic_id = ? ORDER BY surname, name");
        } else {
            $stmtOwnersAll = $db->prepare("SELECT o.* FROM owners o JOIN vet_owner_relations r ON r.owner_id=o.id AND r.clinic_id=o.clinic_id WHERE o.clinic_id = ? AND r.status='active' ORDER BY o.surname, o.name");
        }
        $stmtOwnersAll->execute([$clinicId]);
        $ownersAll = $stmtOwnersAll->fetchAll(PDO::FETCH_ASSOC);

        if (vr_policy_is_admin($user)) {
            $stmtPetsAll = $db->prepare("
                SELECT p.id, p.name AS pet_name, p.species, p.owner_id, o.name AS owner_name, o.surname AS owner_surname
                FROM pets p
                JOIN owners o ON p.owner_id = o.id
                WHERE p.clinic_id = ?
                ORDER BY o.surname, o.name, p.name
            ");
        } else {
            $stmtPetsAll = $db->prepare("
                SELECT p.id, p.name AS pet_name, p.species, p.owner_id, o.name AS owner_name, o.surname AS owner_surname
                FROM pets p
                JOIN owners o ON p.owner_id = o.id
                JOIN vet_owner_relations r ON r.owner_id=o.id AND r.clinic_id=o.clinic_id
                WHERE p.clinic_id = ? AND r.status='active'
                ORDER BY o.surname, o.name, p.name
            ");
        }
        $stmtPetsAll->execute([$clinicId]);
        $petsAll = $stmtPetsAll->fetchAll(PDO::FETCH_ASSOC);

        // Cancella appuntamento
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && ( $_POST['form_action'] ?? '' ) === 'delete_appointment') {
            $appointment_id = (int)($_POST['appointment_id'] ?? 0);
            if ($appointment_id <= 0) {
                $appointment_error = 'Appuntamento non valido.';
            } else {
                if (vr_policy_is_admin($user)) {
                    $stmtDelCheck = $db->prepare("SELECT date, owner_id FROM appointments WHERE id = ? AND clinic_id = ?");
                } else {
                    $stmtDelCheck = $db->prepare("
                        SELECT a.date, a.owner_id
                        FROM appointments a
                        JOIN owners o ON o.id = a.owner_id
                        JOIN vet_owner_relations r ON r.owner_id=o.id AND r.clinic_id=o.clinic_id
                        WHERE a.id = ? AND a.clinic_id = ? AND r.status='active'
                        LIMIT 1
                    ");
                }
                $stmtDelCheck->execute([$appointment_id, $clinicId]);
                $appRow = $stmtDelCheck->fetch(PDO::FETCH_ASSOC);
                if (!$appRow) {
                    $appointment_error = 'Appuntamento non trovato.';
                } else {
                    if (vr_policy_is_admin($user)) {
                        $stmtDel = $db->prepare("DELETE FROM appointments WHERE id = ? AND clinic_id = ?");
                        $stmtDel->execute([$appointment_id, $clinicId]);
                    } else {
                        $oid = (int)($appRow['owner_id'] ?? 0);
                        $stmtDel = $db->prepare("DELETE FROM appointments WHERE id = ? AND clinic_id = ? AND owner_id = ?");
                        $stmtDel->execute([$appointment_id, $clinicId, $oid]);
                    }
                    $appointment_success = 'Appuntamento eliminato.';
                    if (!empty($appRow['date'])) {
                        $selectedDate = $appRow['date'];
                    }
                }
            }
        }

        // Sposta appuntamento (cambio data/ora)
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && ( $_POST['form_action'] ?? '' ) === 'update_appointment') {
            $appointment_id = (int)($_POST['appointment_id'] ?? 0);
            $date = trim($_POST['date'] ?? '');
            $time = trim($_POST['time'] ?? '');
            if ($appointment_id <= 0 || $date === '' || $time === '') {
                $appointment_error = 'Data e ora sono obbligatorie per spostare un appuntamento.';
            } else {
                if (vr_policy_is_admin($user)) {
                    $stmtUpdCheck = $db->prepare("SELECT * FROM appointments WHERE id = ? AND clinic_id = ?");
                } else {
                    $stmtUpdCheck = $db->prepare("
                        SELECT a.*
                        FROM appointments a
                        JOIN owners o ON o.id = a.owner_id
                        JOIN vet_owner_relations r ON r.owner_id=o.id AND r.clinic_id=o.clinic_id
                        WHERE a.id = ? AND a.clinic_id = ? AND r.status='active'
                        LIMIT 1
                    ");
                }
                $stmtUpdCheck->execute([$appointment_id, $clinicId]);
                $appRow = $stmtUpdCheck->fetch(PDO::FETCH_ASSOC);
                if (!$appRow) {
                    $appointment_error = 'Appuntamento non trovato.';
                } else {
                    // Se cambia lo slot, controlla che non sia occupato
                    if ($appRow['date'] !== $date || $appRow['time'] !== $time) {
                        $stmtCheck = $db->prepare("SELECT COUNT(*) FROM appointments WHERE clinic_id = ? AND date = ? AND time = ? AND id <> ?");
                        $stmtCheck->execute([$clinicId, $date, $time, $appointment_id]);
                        $count = (int)$stmtCheck->fetchColumn();
                        if ($count > 0) {
                            $appointment_error = 'Lo slot selezionato è già occupato.';
                        } else {
                            $now = vr_now_iso();
                            if (vr_policy_is_admin($user)) {
                                $stmtUpd = $db->prepare("UPDATE appointments SET date = ?, time = ?, updated_at = ? WHERE id = ? AND clinic_id = ?");
                                $stmtUpd->execute([$date, $time, $now, $appointment_id, $clinicId]);
                            } else {
                                $stmtUpd = $db->prepare("UPDATE appointments SET date = ?, time = ?, updated_at = ? WHERE id = ? AND clinic_id = ? AND owner_id = ?");
                                $stmtUpd->execute([$date, $time, $now, $appointment_id, $clinicId, (int)($appRow['owner_id'] ?? 0)]);
                            }
                            $appointment_success = 'Appuntamento spostato correttamente.';
                            $selectedDate = $date;
                        }
                    } else {
                        $appointment_success = "Nessuna modifica allo slot dell'appuntamento.";
                    }
                }
            }
        }

        // Crea appuntamento
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && ( $_POST['form_action'] ?? '' ) === 'create_appointment') {
            $date = trim($_POST['date'] ?? '');
            $time = trim($_POST['time'] ?? '');
            $owner_id = (int)($_POST['owner_id'] ?? 0);
            $pet_id = (int)($_POST['pet_id'] ?? 0);
            $type = trim($_POST['type'] ?? '');
            $notes = trim($_POST['notes'] ?? '');

            if ($date === '' || $time === '' || $owner_id <= 0 || $pet_id <= 0) {
                $appointment_error = 'Seleziona cliente, animale, data e orario.';
            } else {
                // Consent gating: non-admin can create appointments only for ACTIVE owner links
                if (!vr_consent_staff_can_access_owner($db, $user, $clinicId, $owner_id)) {
                    $appointment_error = 'Proprietario non autorizzato o collegamento non attivo.';
                } else {
                // pet deve appartenere al proprietario e alla clinica
                $stmtPet = $db->prepare("
                    SELECT p.*, o.id AS owner_id
                    FROM pets p
                    JOIN owners o ON p.owner_id = o.id
                    WHERE p.id = ? AND p.clinic_id = ?
                ");
                $stmtPet->execute([$pet_id, $clinicId]);
                $petRow = $stmtPet->fetch(PDO::FETCH_ASSOC);
                if (!$petRow || (int)$petRow['owner_id'] !== $owner_id) {
                    $appointment_error = 'Animale non valido per questo proprietario.';
                } else {
                    // controlla slot
                    $stmtCheck = $db->prepare("SELECT COUNT(*) FROM appointments WHERE clinic_id = ? AND date = ? AND time = ?");
                    $stmtCheck->execute([$clinicId, $date, $time]);
                    $count = (int)$stmtCheck->fetchColumn();
                    if ($count > 0) {
                        $appointment_error = 'Lo slot selezionato è già occupato.';
                    } else {
                        try {
                            $now = vr_now_iso();
                            $stmtIns = $db->prepare("
                                INSERT INTO appointments (clinic_id, pet_id, owner_id, date, time, type, status, notes_internal, created_at, updated_at)
                                VALUES (?, ?, ?, ?, ?, ?, 'CONFIRMED', ?, ?, ?)
                            ");
                            $stmtIns->execute([
                                $clinicId,
                                $pet_id,
                                $owner_id,
                                $date,
                                $time,
                                $type,
                                $notes,
                                $now,
                                $now
                            ]);
                            $appointment_success = 'Appuntamento creato per il ' . $date . ' alle ' . $time . '.';
                            $selectedDate = $date;
                            $slotTime = '';
                        } catch (Exception $e) {
                            $appointment_error = 'Errore durante il salvataggio: ' . $e->getMessage();
                        }
                    }
                }
                }
            }
        }

        // Week / month helper
        $week_base = $_GET['base_date'] ?? vr_today_date();
        $monday = vr_week_monday($week_base);
        $weekDays = vr_week_days($monday);

        $year = isset($_GET['year']) ? (int)$_GET['year'] : (int)date('Y');
        $month = isset($_GET['month']) ? (int)$_GET['month'] : (int)date('n');
        $info = vr_month_info($year, $month);
        $year = $info['year'];
        $month = $info['month'];
        $firstDay = $info['first_day'];
        $lastDay = $info['last_day'];

        if (vr_policy_is_admin($user)) {
            $stmtMonthApps = $db->prepare("SELECT date, COUNT(*) AS c FROM appointments WHERE clinic_id = ? AND date BETWEEN ? AND ? GROUP BY date");
        } else {
            $stmtMonthApps = $db->prepare("SELECT a.date, COUNT(*) AS c FROM appointments a JOIN owners o ON o.id=a.owner_id JOIN vet_owner_relations r ON r.owner_id=o.id AND r.clinic_id=o.clinic_id WHERE a.clinic_id = ? AND a.date BETWEEN ? AND ? AND r.status='active' GROUP BY a.date");
        }
        $stmtMonthApps->execute([$clinicId, $firstDay, $lastDay]);
        $mapAppCount = [];
        while ($row = $stmtMonthApps->fetch(PDO::FETCH_ASSOC)) {
            $mapAppCount[$row['date']] = (int)$row['c'];
        }

        vr_layout_start('Agenda - ' . VETROOM_APP_NAME, $user, 'appointments');
        ?>
        <h1 class="vr-page-title">Agenda</h1>
        <p class="vr-page-subtitle">Vista settimanale e mensile. Appuntamenti con selezione cliente → animale.</p>

        <div style="margin-bottom:10px;">
            <a href="index.php?page=appointments&view=week&base_date=<?php echo vr_h($monday); ?>" class="vr-button vr-button-secondary" style="margin-right:6px;">Vista settimanale</a>
            <a href="index.php?page=appointments&view=month&year=<?php echo $year; ?>&month=<?php echo $month; ?>" class="vr-button vr-button-secondary">Vista mensile</a>
        </div>

        <?php if ($view === 'week'): ?>
            <div class="vr-card">
                <div class="vr-card-header">Settimana di <?php echo vr_h($monday); ?></div>
                <table class="vr-table">
                    <thead>
                    <tr>
                        <th>Giorno</th>
                        <th>Data</th>
                        <th>Appuntamenti</th>
                        <th>Azioni</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($weekDays as $d): ?>
                        <?php
                        if (vr_policy_is_admin($user)) {
                            $stmtCnt = $db->prepare("SELECT COUNT(*) FROM appointments WHERE clinic_id = ? AND date = ?");
                        } else {
                            $stmtCnt = $db->prepare("SELECT COUNT(*) FROM appointments a JOIN owners o ON o.id=a.owner_id JOIN vet_owner_relations r ON r.owner_id=o.id AND r.clinic_id=o.clinic_id WHERE a.clinic_id = ? AND a.date = ? AND r.status='active'");
                        }
                        $stmtCnt->execute([$clinicId, $d]);
                        $cnt = (int)$stmtCnt->fetchColumn();
                        ?>
                        <tr>
                            <td><?php echo vr_h(date('D', strtotime($d))); ?></td>
                            <td><?php echo vr_h($d); ?></td>
                            <td><?php echo $cnt; ?></td>
                            <td>
                                <a href="index.php?page=appointments&view=month&year=<?php echo $year; ?>&month=<?php echo $month; ?>&selected_date=<?php echo vr_h($d); ?>" class="vr-button" style="font-size:11px;padding:3px 10px;">Gestisci giorno</a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                <div style="margin-top:10px;">
                    <?php
                    $prevWeek = date('Y-m-d', strtotime($monday . ' -7 days'));
                    $nextWeek = date('Y-m-d', strtotime($monday . ' +7 days'));
                    ?>
                    <a href="index.php?page=appointments&view=week&base_date=<?php echo vr_h($prevWeek); ?>" class="vr-button vr-button-secondary" style="font-size:11px;">&laquo; Settimana precedente</a>
                    <a href="index.php?page=appointments&view=week&base_date=<?php echo vr_h($nextWeek); ?>" class="vr-button vr-button-secondary" style="font-size:11px;">Settimana successiva &raquo;</a>
                </div>
            </div>
        <?php endif; ?>

        <div class="vr-card">
            <div class="vr-card-header">Calendario mensile – <?php echo sprintf('%02d/%04d', $month, $year); ?></div>
            <div style="margin-bottom:8px;font-size:12px;">
                <?php
                $firstTs = strtotime($firstDay);
                $prevMonthTs = strtotime('-1 month', $firstTs);
                $nextMonthTs = strtotime('+1 month', $firstTs);
                $prevYear = (int)date('Y', $prevMonthTs);
                $prevMon = (int)date('n', $prevMonthTs);
                $nextYear = (int)date('Y', $nextMonthTs);
                $nextMon = (int)date('n', $nextMonthTs);
                ?>
                <a href="index.php?page=appointments&view=month&year=<?php echo $prevYear; ?>&month=<?php echo $prevMon; ?>" class="vr-button vr-button-secondary" style="font-size:11px;padding:3px 8px;">&laquo; Mese precedente</a>
                <a href="index.php?page=appointments&view=month&year=<?php echo $nextYear; ?>&month=<?php echo $nextMon; ?>" class="vr-button vr-button-secondary" style="font-size:11px;padding:3px 8px;">Mese successivo &raquo;</a>
            </div>
            <?php
            $monthStartTs = strtotime($firstDay);
            $firstDow = (int)date('N', $monthStartTs);
            $calStartTs = $monthStartTs - ($firstDow - 1) * 86400;
            ?>
            <table class="vr-table">
                <thead>
                <tr>
                    <th>Lun</th><th>Mar</th><th>Mer</th><th>Gio</th><th>Ven</th><th>Sab</th><th>Dom</th>
                </tr>
                </thead>
                <tbody>
                <?php
                $dayTs = $calStartTs;
                for ($week = 0; $week < 6; $week++) {
                    echo '<tr>';
                    for ($d = 0; $d < 7; $d++) {
                        $dayDate = date('Y-m-d', $dayTs);
                        $dayNum = (int)date('j', $dayTs);
                        $dayMonth = (int)date('n', $dayTs);
                        echo '<td>';
                        $style = 'font-size:11px;font-weight:600;';
                        if ($dayMonth !== $month) $style .= 'color:#9ca3af;';
                        if ($dayDate === vr_today_date()) $style .= 'color:#0f766e;';
                        echo '<div style="' . $style . '">';
                        echo '<a href="index.php?page=appointments&view=month&year=' . $year . '&month=' . $month . '&selected_date=' . vr_h($dayDate) . '">' . $dayNum . '</a>';
                        echo '</div>';
                        if (isset($mapAppCount[$dayDate])) {
                            echo '<div style="display:inline-block;margin-top:2px;padding:1px 5px;border-radius:999px;font-size:10px;background:#e0f2fe;color:#0369a1;">' . (int)$mapAppCount[$dayDate] . ' app.</div>';
                        }
                        echo '</td>';
                        $dayTs += 86400;
                    }
                    echo '</tr>';
                }
                ?>
                </tbody>
            </table>
        </div>

        <?php
        // Appuntamenti del giorno selezionato (vista a schede)
        if (vr_policy_is_admin($user)) {
            $stmtDayApps = $db->prepare("
                SELECT a.*, p.name AS pet_name, o.name AS owner_name, o.surname AS owner_surname
                FROM appointments a
                JOIN pets p ON a.pet_id = p.id
                JOIN owners o ON a.owner_id = o.id
                WHERE a.clinic_id = ? AND a.date = ?
                ORDER BY a.time
            ");
        } else {
            $stmtDayApps = $db->prepare("
                SELECT a.*, p.name AS pet_name, o.name AS owner_name, o.surname AS owner_surname
                FROM appointments a
                JOIN pets p ON a.pet_id = p.id
                JOIN owners o ON a.owner_id = o.id
                JOIN vet_owner_relations r ON r.owner_id=o.id AND r.clinic_id=o.clinic_id
                WHERE a.clinic_id = ? AND a.date = ? AND r.status='active'
                ORDER BY a.time
            ");
        }
        $stmtDayApps->execute([$clinicId, $selectedDate]);
        $appsOfDay = $stmtDayApps->fetchAll(PDO::FETCH_ASSOC);
        ?>

        <div class="vr-card">
            <div class="vr-card-header">Appuntamenti del giorno <?php echo vr_h($selectedDate); ?></div>
            <?php if (!$appsOfDay): ?>
                <p style="font-size:13px;margin:0;">Nessun appuntamento per questo giorno.</p>
            <?php else: ?>
                <div class="vr-appointments-day">
                    <?php foreach ($appsOfDay as $app): ?>
                        <div class="vr-appointment-card">
                            <div class="vr-appointment-card-header">
                                <span class="vr-appointment-time"><?php echo vr_h(substr($app['time'], 0, 5)); ?></span>
                                <span class="vr-appointment-pet"><?php echo vr_h($app['pet_name']); ?></span>
                            </div>
                            <div class="vr-appointment-owner">
                                <?php echo vr_h($app['owner_name'] . ' ' . $app['owner_surname']); ?>
                            </div>
                            <?php if (!empty($app['type'])): ?>
                                <div class="vr-appointment-type">
                                    <?php echo vr_h($app['type']); ?>
                                </div>
                            <?php endif; ?>
                            <?php if (!empty($app['notes_internal'])): ?>
                                <div class="vr-appointment-notes">
                                    <?php echo nl2br(vr_h($app['notes_internal'])); ?>
                                </div>
                            <?php endif; ?>
                            <div class="vr-appointment-actions">
                                <form method="post" style="display:inline;" onsubmit="return confirm('Eliminare questo appuntamento?');">
                                    <input type="hidden" name="form_action" value="delete_appointment">
                                    <?php echo vr_staff_csrf_field(); ?>
                                    <input type="hidden" name="appointment_id" value="<?php echo (int)$app['id']; ?>">
                                    <button type="submit" class="vr-button vr-button-danger vr-button-xs vr-icon-submit"><img class="vr-action-icon vr-btn-ico" src="public/icons/delete_appointment.png" alt="Elimina"><span class="vr-btn-label">Elimina</span></button>
                                </form>
                                <form method="post" style="display:inline;">
                                    <input type="hidden" name="form_action" value="update_appointment">
                                    <?php echo vr_staff_csrf_field(); ?>
                                    <input type="hidden" name="appointment_id" value="<?php echo (int)$app['id']; ?>">
                                    <input type="date" name="date" value="<?php echo vr_h($app['date']); ?>" class="vr-input vr-input-xs">
                                    <input type="time" name="time" value="<?php echo vr_h(substr($app['time'], 0, 5)); ?>" class="vr-input vr-input-xs">
                                    <button type="submit" class="vr-button vr-button-secondary vr-button-xs">Sposta</button>
                                </form>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>

        <?php
        $slots = vetroom_get_slots_status($clinicId, $selectedDate);
        ?>
        <div class="vr-card">
            <div class="vr-card-header">Slot per il giorno <?php echo vr_h($selectedDate); ?></div>
            <p style="font-size:12px;margin:0 0 6px;">Verde = libero, rosso = occupato. Clicca uno slot verde per usarlo come orario.</p>
            <?php if (!$slots): ?>
                <p style="font-size:13px;margin:0;">Nessuna disponibilità configurata per questo giorno.</p>
            <?php else: ?>
                <div style="margin-top:6px;margin-bottom:4px;">
                    <?php foreach ($slots as $s): ?>
                        <?php if ($s['busy']): ?>
                            <span style="display:inline-block;margin:2px 4px 2px 0;padding:4px 8px;border-radius:999px;font-size:11px;background:#fee2e2;color:#b91c1c;"><?php echo vr_h($s['time']); ?></span>
                        <?php else: ?>
                            <a href="index.php?page=appointments&view=month&year=<?php echo $year; ?>&month=<?php echo $month; ?>&selected_date=<?php echo vr_h($selectedDate); ?>&slot_time=<?php echo vr_h($s['time']); ?>"
                               style="display:inline-block;margin:2px 4px 2px 0;padding:4px 8px;border-radius:999px;font-size:11px;background:#dcfce7;color:#166534;text-decoration:none;">
                                <?php echo vr_h($s['time']); ?>
                            </a>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>

        <div class="vr-card vr-collapsible" id="new_appointment">
            <div class="vr-card-header">Nuovo appuntamento</div>
            <?php if ($appointment_error): ?>
                <div class="vr-alert vr-alert-error"><?php echo vr_h($appointment_error); ?></div>
            <?php endif; ?>
            <?php if ($appointment_success): ?>
                <div class="vr-alert vr-alert-success"><?php echo vr_h($appointment_success); ?></div>
            <?php endif; ?>

            <form method="post">
                <input type="hidden" name="form_action" value="create_appointment">
                <?php echo vr_staff_csrf_field(); ?>
                <div class="vr-form-row">
                    <label class="vr-label" for="owner_search">Cliente</label>
                    <input class="vr-input" type="text" id="owner_search" placeholder="Digita nome o cognome..." autocomplete="off">
                    <select class="vr-select" id="owner_id_app" name="owner_id" required>
                        <option value="">Seleziona proprietario...</option>
                        <?php foreach ($ownersAll as $ow): ?>
                            <option value="<?php echo (int)$ow['id']; ?>"><?php echo vr_h($ow['surname'] . ' ' . $ow['name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="vr-form-row">
                    <label class="vr-label" for="pet_id_app">Animale</label>
                    <select class="vr-select" id="pet_id_app" name="pet_id" required>
                        <option value="">Seleziona prima il proprietario...</option>
                        <?php foreach ($petsAll as $pRow): ?>
                            <option value="<?php echo (int)$pRow['id']; ?>" data-owner-id="<?php echo (int)$pRow['owner_id']; ?>">
                                <?php echo vr_h($pRow['pet_name'] . ' – ' . $pRow['species'] . ' (' . $pRow['owner_surname'] . ' ' . $pRow['owner_name'] . ')'); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <div style="font-size:11px;color:#6b7280;">L'elenco animali si filtra automaticamente in base al proprietario.</div>
                </div>
                <div class="vr-form-row">
                    <label class="vr-label" for="date_app">Data</label>
                    <input class="vr-input" type="date" id="date_app" name="date" required value="<?php echo vr_h($selectedDate); ?>">
                </div>
                <div class="vr-form-row">
                    <label class="vr-label" for="time_app">Orario</label>
                    <input class="vr-input" type="time" id="time_app" name="time" required value="<?php echo vr_h($slotTime); ?>">
                </div>
                <div class="vr-form-row">
                    <label class="vr-label" for="type_app">Tipo visita (facoltativo)</label>
                    <input class="vr-input" type="text" id="type_app" name="type" placeholder="Es. visita clinica, visita oculistica...">
                </div>
                <div class="vr-form-row">
                    <label class="vr-label" for="notes_app">Note interne (facoltative)</label>
                    <textarea class="vr-textarea" id="notes_app" name="notes"></textarea>
                </div>
                <button type="submit" class="vr-button vr-icon-submit"><img class="vr-action-icon vr-btn-ico" src="public/icons/create_appointment.png" alt="Crea appuntamento"><span class="vr-btn-label">Crea appuntamento</span></button>
            </form>
        </div>

        <script>
        (function() {
            const ownerSelect = document.getElementById('owner_id_app');
            const petSelect = document.getElementById('pet_id_app');
            if (!ownerSelect || !petSelect) return;
            const allOptions = Array.from(petSelect.querySelectorAll('option'));

            function filterPets() {
                const ownerId = ownerSelect.value;
                const placeholder = allOptions[0];
                petSelect.innerHTML = '';
                petSelect.appendChild(placeholder.cloneNode(true));
                allOptions.slice(1).forEach(function(opt) {
                    const oid = opt.getAttribute('data-owner-id');
                    if (!ownerId || ownerId === oid) {
                        petSelect.appendChild(opt.cloneNode(true));
                    }
                });
            }

            ownerSelect.addEventListener('change', filterPets);
            filterPets();

            // Ricerca testuale cliente
            const ownerSearch = document.getElementById('owner_search');
            if (ownerSearch) {
                const ownerOptions = Array.from(ownerSelect.querySelectorAll('option')).slice(1); // salta il placeholder

                function findOwnerMatch(query) {
                    const q = query.toLowerCase();
                    for (let i = 0; i < ownerOptions.length; i++) {
                        const opt = ownerOptions[i];
                        if (opt.textContent.toLowerCase().includes(q)) {
                            return opt;
                        }
                    }
                    return null;
                }

                ownerSearch.addEventListener('input', function() {
                    const q = ownerSearch.value.trim();
                    if (!q) {
                        ownerSelect.value = '';
                        ownerSelect.dispatchEvent(new Event('change'));
                        return;
                    }
                    const match = findOwnerMatch(q);
                    if (match) {
                        ownerSelect.value = match.value;
                        ownerSelect.dispatchEvent(new Event('change'));
                    }
                });
            }
        })();
        </script>

        <?php
        vr_layout_end();
        break;
    }

    /* PROPRIETARI */
    case 'owners': {
        $owners_error = '';
        $owners_success = '';

        if ($_SERVER['REQUEST_METHOD'] === 'POST' && ( $_POST['form_action'] ?? '' ) === 'delete_owner') {
            $owner_id = (int)($_POST['owner_id'] ?? 0);
            if ($owner_id <= 0) {
                $owners_error = 'Proprietario non valido.';
            } else {
                if (vr_policy_is_admin($user)) {
                    $stmtCheckOwner = $db->prepare("SELECT id FROM owners WHERE id = ? AND clinic_id = ?");
                    $stmtCheckOwner->execute([$owner_id, $clinicId]);
                } else {
                    $stmtCheckOwner = $db->prepare("SELECT o.id FROM owners o JOIN vet_owner_relations r ON r.owner_id=o.id AND r.clinic_id=o.clinic_id WHERE o.id = ? AND o.clinic_id = ? AND r.status='active' LIMIT 1");
                    $stmtCheckOwner->execute([$owner_id, $clinicId]);
                }
                if (!$stmtCheckOwner->fetchColumn()) {
                    $owners_error = 'Proprietario non trovato.';
                } else {
                    // ON DELETE CASCADE su pets e appointments
                    $stmtDelOwner = $db->prepare("DELETE FROM owners WHERE id = ? AND clinic_id = ?");
                    $stmtDelOwner->execute([$owner_id, $clinicId]);
                    $owners_success = 'Proprietario eliminato (con eventuali animali e appuntamenti collegati).';
                }
            }
        }

        if ($_SERVER['REQUEST_METHOD'] === 'POST' && ( $_POST['form_action'] ?? '' ) === 'create_owner') {
            $name = trim($_POST['name'] ?? '');
            $surname = trim($_POST['surname'] ?? '');
            $email = trim($_POST['email'] ?? '');
            $phone = trim($_POST['phone'] ?? '');
            $birth_date = trim($_POST['birth_date'] ?? '');
            $fiscal_code = strtoupper(preg_replace('/\s+/', '', trim($_POST['fiscal_code'] ?? '')));
            $address_street = trim($_POST['address_street'] ?? '');
            $address_number = trim($_POST['address_number'] ?? '');
            $address_city = trim($_POST['address_city'] ?? '');
            $address_province = trim($_POST['address_province'] ?? '');
            $address_state = trim($_POST['address_state'] ?? '');
            $address_zip = trim($_POST['address_zip'] ?? '');
            $billing_is_different = isset($_POST['billing_is_different']) ? 1 : 0;
            $billing_street = trim($_POST['billing_street'] ?? '');
            $billing_number = trim($_POST['billing_number'] ?? '');
            $billing_city = trim($_POST['billing_city'] ?? '');
            $billing_province = trim($_POST['billing_province'] ?? '');
            $billing_state = trim($_POST['billing_state'] ?? '');
            $billing_zip = trim($_POST['billing_zip'] ?? '');

            if ($name === '' || $surname === '') {
                $owners_error = 'Nome e cognome sono obbligatori.';
            } elseif ($fiscal_code !== '' && !vr_cf_is_valid($fiscal_code)) {
                $owners_error = 'Codice fiscale non valido.';
            } else {
                try {
                    $now = vr_now_iso();

                    // If an OWNER account already exists for this CF, require approval (pending link)
                    $ownerAccountId = null;
                    if ($fiscal_code !== '') {
                        $stmtAcc = $db->prepare("SELECT id FROM owner_accounts WHERE upper(fiscal_code)=? LIMIT 1");
                        $stmtAcc->execute([$fiscal_code]);
                        $tmp = $stmtAcc->fetchColumn();
                        if ($tmp !== false && $tmp !== null) {
                            $ownerAccountId = (int)$tmp;
                        }
                    }

                    // Avoid duplicates within the clinic for the same CF: update if already present
                    $existingOwnerId = 0;
                    if ($fiscal_code !== '') {
                        $stmtFind = $db->prepare("SELECT id FROM owners WHERE clinic_id=? AND upper(fiscal_code)=? ORDER BY id ASC LIMIT 1");
                        $stmtFind->execute([$clinicId, $fiscal_code]);
                        $existingOwnerId = (int)($stmtFind->fetchColumn() ?: 0);
                    }

                    if ($existingOwnerId > 0) {
                        $stmtUp = $db->prepare("
                            UPDATE owners SET
                                owner_account_id = COALESCE(?, owner_account_id),
                                name = ?, surname = ?, birth_date = ?, fiscal_code = ?,
                                email = ?, phone = ?,
                                address_street = ?, address_number = ?, address_city = ?, address_province = ?, address_state = ?, address_zip = ?,
                                billing_is_different = ?, billing_street = ?, billing_number = ?, billing_city = ?, billing_province = ?, billing_state = ?, billing_zip = ?,
                                updated_at = ?
                            WHERE id = ? AND clinic_id = ?
                        ");
                        $stmtUp->execute([
                            $ownerAccountId,
                            $name, $surname, $birth_date, $fiscal_code,
                            $email, $phone,
                            $address_street, $address_number, $address_city, $address_province, $address_state, $address_zip,
                            $billing_is_different, $billing_street, $billing_number, $billing_city, $billing_province, $billing_state, $billing_zip,
                            $now,
                            $existingOwnerId, $clinicId
                        ]);
                        $owner_id = $existingOwnerId;
                    } else {
                        $stmtIns = $db->prepare("
                            INSERT INTO owners (
                                clinic_id, owner_account_id, name, surname, birth_date, fiscal_code,
                                email, phone,
                                address_street, address_number, address_city, address_province, address_state, address_zip,
                                billing_is_different, billing_street, billing_number, billing_city, billing_province, billing_state, billing_zip,
                                created_at, updated_at
                            ) VALUES (
                                ?, ?, ?, ?, ?, ?,
                                ?, ?,
                                ?, ?, ?, ?, ?, ?,
                                ?, ?, ?, ?, ?, ?, ?, ?, ?
                            )
                        ");
                        $stmtIns->execute([
                            $clinicId, $ownerAccountId,
                            $name, $surname, $birth_date, $fiscal_code,
                            $email, $phone,
                            $address_street, $address_number, $address_city, $address_province, $address_state, $address_zip,
                            $billing_is_different, $billing_street, $billing_number, $billing_city, $billing_province, $billing_state, $billing_zip,
                            $now, $now
                        ]);
                        $owner_id = (int)$db->lastInsertId();
                    }

                    // Consent relation: active by default, but pending if OWNER account already exists
                    $rel = vr_consent_get_relation($db, $clinicId, (int)$owner_id);
                    if ($ownerAccountId !== null && $ownerAccountId > 0) {
                        $cur = $rel ? vr_consent_normalize_status((string)($rel['status'] ?? '')) : '';
                        $desired = ($cur === 'active') ? 'active' : 'pending';
                        vr_consent_upsert_relation($db, $clinicId, (int)$owner_id, $ownerAccountId, $desired);
                        if ($desired === 'pending') {
                            $owners_success = 'Proprietario inserito. Richiesta di collegamento inviata al proprietario: in attesa di approvazione.';
                        } else {
                            $owners_success = 'Proprietario inserito correttamente.';
                        }
                    } else {
                        // Ensure relation exists for legacy/local owners
                        if (!$rel) {
                            vr_consent_upsert_relation($db, $clinicId, (int)$owner_id, null, 'active');
                        }
                        $owners_success = 'Proprietario inserito correttamente.';
                    }
                } catch (Exception $e) {
                    $owners_error = 'Errore durante il salvataggio: ' . $e->getMessage();
                }
            }
        }

        $q = trim($_GET['q'] ?? '');
        if ($q !== '') {
            $like = '%' . $q . '%';
            if (vr_policy_is_admin($user)) {
                $stmtOwners = $db->prepare("
                    SELECT * FROM owners
                    WHERE clinic_id = ?
                      AND (name LIKE ? OR surname LIKE ? OR email LIKE ? OR phone LIKE ? OR fiscal_code LIKE ?)
                    ORDER BY surname, name
                ");
                $stmtOwners->execute([$clinicId, $like, $like, $like, $like, $like]);
            } else {
                $stmtOwners = $db->prepare("
                    SELECT o.*
                    FROM owners o
                    JOIN vet_owner_relations r ON r.owner_id=o.id AND r.clinic_id=o.clinic_id
                    WHERE o.clinic_id = ?
                      AND r.status = 'active'
                      AND (o.name LIKE ? OR o.surname LIKE ? OR o.email LIKE ? OR o.phone LIKE ? OR o.fiscal_code LIKE ?)
                    ORDER BY o.surname, o.name
                ");
                $stmtOwners->execute([$clinicId, $like, $like, $like, $like, $like]);
            }
        } else {
            if (vr_policy_is_admin($user)) {
                $stmtOwners = $db->prepare("SELECT * FROM owners WHERE clinic_id = ? ORDER BY surname, name");
            } else {
                $stmtOwners = $db->prepare("SELECT o.* FROM owners o JOIN vet_owner_relations r ON r.owner_id=o.id AND r.clinic_id=o.clinic_id WHERE o.clinic_id = ? AND r.status='active' ORDER BY o.surname, o.name");
            }
            $stmtOwners->execute([$clinicId]);
        }
        $owners = $stmtOwners->fetchAll(PDO::FETCH_ASSOC);

        vr_layout_start('Proprietari - ' . VETROOM_APP_NAME, $user, 'owners');
        ?>
        <h1 class="vr-page-title">Proprietari</h1>
        <p class="vr-page-subtitle">Anagrafiche clienti con indirizzi completi.</p>

        <div class="vr-card">
            <div class="vr-card-header">Ricerca</div>
            <form method="get">
                <input type="hidden" name="page" value="owners">
                <div class="vr-form-row" style="display:flex;gap:6px;">
                    <input class="vr-input" type="text" name="q" placeholder="Cerca per nome, cognome, CF, email, telefono..." value="<?php echo vr_h($q); ?>">
                    <button class="vr-button vr-button-secondary" type="submit">Cerca</button>
                </div>
            </form>
        </div>

        <div class="vr-card vr-collapsible" id="new_owner">
            <div class="vr-card-header">Nuovo proprietario</div>
            <?php if ($owners_error): ?>
                <div class="vr-alert vr-alert-error"><?php echo vr_h($owners_error); ?></div>
            <?php endif; ?>
            <?php if ($owners_success): ?>
                <div class="vr-alert vr-alert-success"><?php echo vr_h($owners_success); ?></div>
            <?php endif; ?>

            <form method="post">
                <input type="hidden" name="form_action" value="create_owner">
                <?php echo vr_staff_csrf_field(); ?>
                <div class="vr-form-row">
                    <label class="vr-label" for="name_owner">Nome *</label>
                    <input class="vr-input" type="text" id="name_owner" name="name" required>
                </div>
                <div class="vr-form-row">
                    <label class="vr-label" for="surname_owner">Cognome *</label>
                    <input class="vr-input" type="text" id="surname_owner" name="surname" required>
                </div>
                <div class="vr-form-row">
                    <label class="vr-label" for="birth_date_owner">Data di nascita</label>
                    <input class="vr-input" type="date" id="birth_date_owner" name="birth_date">
                </div>
                <div class="vr-form-row">
                    <label class="vr-label" for="fiscal_code">Codice fiscale</label>
                    <input class="vr-input" type="text" id="fiscal_code" name="fiscal_code" maxlength="16">
                </div>
                <div class="vr-form-row">
                    <label class="vr-label" for="email_owner">Email</label>
                    <input class="vr-input" type="email" id="email_owner" name="email">
                </div>
                <div class="vr-form-row">
                    <label class="vr-label" for="phone_owner">Telefono</label>
                    <input class="vr-input" type="text" id="phone_owner" name="phone">
                </div>

                <div class="vr-form-row">
                    <label class="vr-label">Indirizzo di residenza</label>
                </div>
                <div class="vr-form-row">
                    <input class="vr-input" type="text" name="address_street" placeholder="Via / Piazza">
                </div>
                <div class="vr-form-row" style="display:flex;gap:6px;">
                    <input class="vr-input" type="text" name="address_number" placeholder="Civico">
                    <input class="vr-input" type="text" name="address_zip" placeholder="CAP">
                </div>
                <div class="vr-form-row" style="display:flex;gap:6px;">
                    <input class="vr-input" type="text" name="address_city" placeholder="Città">
                    <input class="vr-input" type="text" name="address_province" placeholder="Provincia">
                    <input class="vr-input" type="text" name="address_state" placeholder="Stato">
                </div>

                <div class="vr-form-row vr-checkbox-row">
                    <input type="checkbox" id="billing_is_different" name="billing_is_different" value="1">
                    <label for="billing_is_different">Indirizzo di fatturazione diverso da residenza</label>
                </div>

                <div class="vr-form-row">
                    <label class="vr-label">Indirizzo di fatturazione (se diverso)</label>
                </div>
                <div class="vr-form-row">
                    <input class="vr-input" type="text" name="billing_street" placeholder="Via / Piazza">
                </div>
                <div class="vr-form-row" style="display:flex;gap:6px;">
                    <input class="vr-input" type="text" name="billing_number" placeholder="Civico">
                    <input class="vr-input" type="text" name="billing_zip" placeholder="CAP">
                </div>
                <div class="vr-form-row" style="display:flex;gap:6px;">
                    <input class="vr-input" type="text" name="billing_city" placeholder="Città">
                    <input class="vr-input" type="text" name="billing_province" placeholder="Provincia">
                    <input class="vr-input" type="text" name="billing_state" placeholder="Stato">
                </div>

                <button type="submit" class="vr-button vr-icon-submit"><img class="vr-action-icon vr-btn-ico" src="public/icons/create_owner.png" alt="Salva proprietario"><span class="vr-btn-label">Salva proprietario</span></button>
            </form>
        </div>

        <div class="vr-card">
            <div class="vr-card-header">Elenco proprietari</div>
            <?php if (!$owners): ?>
                <p style="font-size:13px;margin:0;">Nessun proprietario trovato.</p>
            <?php else: ?>
                <table class="vr-table">
                    <thead>
                    <tr>
                        <th>Nome</th>
                        <th>CF</th>
                        <th>Email</th>
                        <th>Telefono</th>
                        <th>Azioni</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($owners as $o): ?>
                        <tr>
                            <td><?php echo vr_h($o['surname'] . ' ' . $o['name']); ?></td>
                            <td><?php echo vr_h($o['fiscal_code']); ?></td>
                            <td><?php echo vr_h($o['email']); ?></td>
                            <td><?php echo vr_h($o['phone']); ?></td>
                            <td>
                                <a href="index.php?page=owner_detail&id=<?php echo (int)$o['id']; ?>" class="vr-button" style="font-size:11px;padding:3px 10px;">Scheda</a>
                                <form method="post" style="display:inline;margin-left:4px;" onsubmit="return confirm('Eliminare questo proprietario e tutti gli animali e appuntamenti collegati?');">
                                    <input type="hidden" name="form_action" value="delete_owner">
                                    <?php echo vr_staff_csrf_field(); ?>
                                    <input type="hidden" name="owner_id" value="<?php echo (int)$o['id']; ?>">
                                    <button type="submit" class="vr-button vr-button-danger vr-icon-submit"><img class="vr-action-icon vr-btn-ico" src="public/icons/delete_owner.png" alt="Elimina"><span class="vr-btn-label">Elimina</span></button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
        <?php
        vr_layout_end();
        break;
    }

    case 'owner_detail': {
        $ownerId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
        $msg_error = '';
        $msg_success = '';
        if (vr_policy_is_admin($user)) {
            $stmtOwner = $db->prepare("SELECT * FROM owners WHERE id = ? AND clinic_id = ?");
        } else {
            $stmtOwner = $db->prepare("SELECT o.* FROM owners o JOIN vet_owner_relations r ON r.owner_id=o.id AND r.clinic_id=o.clinic_id WHERE o.id = ? AND o.clinic_id = ? AND r.status='active' LIMIT 1");
        }
        $stmtOwner->execute([$ownerId, $clinicId]);
        $owner = $stmtOwner->fetch(PDO::FETCH_ASSOC);
        if (!$owner) {
            vr_layout_start('Proprietario non trovato - ' . VETROOM_APP_NAME, $user, 'owners');
            echo '<div class="vr-card"><p style="font-size:13px;margin:0;">Proprietario non trovato.</p></div>';
            vr_layout_end();
            break;
        }

        // Modifica scheda proprietario
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && ( $_POST['form_action'] ?? '' ) === 'update_owner') {
            $name = trim($_POST['name'] ?? '');
            $surname = trim($_POST['surname'] ?? '');
            $birth_date = trim($_POST['birth_date'] ?? '');
            $fiscal_code = strtoupper(preg_replace('/\s+/', '', trim($_POST['fiscal_code'] ?? '')));
            $email = trim($_POST['email'] ?? '');
            $phone = trim($_POST['phone'] ?? '');

            $address_street = trim($_POST['address_street'] ?? '');
            $address_number = trim($_POST['address_number'] ?? '');
            $address_city = trim($_POST['address_city'] ?? '');
            $address_province = trim($_POST['address_province'] ?? '');
            $address_state = trim($_POST['address_state'] ?? '');
            $address_zip = trim($_POST['address_zip'] ?? '');

            $billing_is_different = !empty($_POST['billing_is_different']) ? 1 : 0;
            $billing_street = trim($_POST['billing_street'] ?? '');
            $billing_number = trim($_POST['billing_number'] ?? '');
            $billing_city = trim($_POST['billing_city'] ?? '');
            $billing_province = trim($_POST['billing_province'] ?? '');
            $billing_state = trim($_POST['billing_state'] ?? '');
            $billing_zip = trim($_POST['billing_zip'] ?? '');

            if ($name === '' || $surname === '') {
                $msg_error = 'Nome e cognome sono obbligatori.';
            } elseif ($fiscal_code !== '' && !vr_cf_is_valid($fiscal_code)) {
                $msg_error = 'Codice fiscale non valido.';
            } else {
                try {
                    $now = vr_now_iso();
                    $stmtUpO = $db->prepare("
                        UPDATE owners SET
                            name = ?, surname = ?, birth_date = ?, fiscal_code = ?,
                            email = ?, phone = ?,
                            address_street = ?, address_number = ?, address_city = ?, address_province = ?, address_state = ?, address_zip = ?,
                            billing_is_different = ?, billing_street = ?, billing_number = ?, billing_city = ?, billing_province = ?, billing_state = ?, billing_zip = ?,
                            updated_at = ?
                        WHERE id = ? AND clinic_id = ?
                    ");
                    $stmtUpO->execute([
                        $name, $surname, $birth_date, $fiscal_code,
                        $email, $phone,
                        $address_street, $address_number, $address_city, $address_province, $address_state, $address_zip,
                        $billing_is_different, $billing_street, $billing_number, $billing_city, $billing_province, $billing_state, $billing_zip,
                        $now,
                        $ownerId, $clinicId
                    ]);

                    // If fiscal_code matches an existing OWNER account, link the profile to the account.
                    // If the link is not already ACTIVE, we create/refresh a PENDING consent request.
                    if ($fiscal_code !== '') {
                        $stmtAcc = $db->prepare("SELECT id FROM owner_accounts WHERE upper(fiscal_code)=? LIMIT 1");
                        $stmtAcc->execute([$fiscal_code]);
                        $accId = (int)($stmtAcc->fetchColumn() ?: 0);
                        if ($accId > 0) {
                            $stmtLink = $db->prepare("UPDATE owners SET owner_account_id = ?, updated_at = ? WHERE id = ? AND clinic_id = ?");
                            $stmtLink->execute([$accId, $now, $ownerId, $clinicId]);

                            $rel = vr_consent_get_relation($db, $clinicId, $ownerId);
                            $cur = $rel ? vr_consent_normalize_status((string)($rel['status'] ?? '')) : '';
                            $desired = ($cur === 'active') ? 'active' : 'pending';
                            vr_consent_upsert_relation($db, $clinicId, $ownerId, $accId, $desired);
                        }
                    }

                    $msg_success = 'Scheda proprietario aggiornata.';
                    // Reload
                    if (vr_policy_is_admin($user)) {
                        $stmtOwner = $db->prepare("SELECT * FROM owners WHERE id = ? AND clinic_id = ?");
                    } else {
                        $stmtOwner = $db->prepare("SELECT o.* FROM owners o JOIN vet_owner_relations r ON r.owner_id=o.id AND r.clinic_id=o.clinic_id WHERE o.id = ? AND o.clinic_id = ? AND r.status='active' LIMIT 1");
                    }
                    $stmtOwner->execute([$ownerId, $clinicId]);
                    $owner = $stmtOwner->fetch(PDO::FETCH_ASSOC);
                } catch (Exception $e) {
                    $msg_error = 'Errore durante il salvataggio: ' . vr_h($e->getMessage());
                }
            }
        }

        // Cancellazione visita (da storico)
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && ( $_POST['form_action'] ?? '' ) === 'delete_visit') {
            $del_id = isset($_POST['visit_id']) ? (int)$_POST['visit_id'] : 0;
            if ($del_id > 0) {
                try {
                    $stmtDel = $db->prepare("DELETE FROM visits WHERE id = ? AND clinic_id = ? AND owner_id = ?");
                    $stmtDel->execute([$del_id, $clinicId, $ownerId]);
                    $msg_success = 'Visita eliminata correttamente.';
                } catch (Exception $e) {
                    $msg_error = 'Errore nell\'eliminazione: ' . $e->getMessage();
                }
            }
        }
    $stmtPets = $db->prepare("SELECT * FROM pets WHERE owner_id = ? AND clinic_id = ? ORDER BY name");
        $stmtPets->execute([$ownerId, $clinicId]);
        $pets = $stmtPets->fetchAll(PDO::FETCH_ASSOC);

        $stmtVisits = $db->prepare("
            SELECT v.*, p.name AS pet_name, vf.name AS visit_form_name
            FROM visits v
            JOIN pets p ON v.pet_id = p.id
            LEFT JOIN visit_forms vf ON vf.id = v.visit_form_id AND vf.clinic_id = v.clinic_id
            WHERE v.owner_id = ? AND v.clinic_id = ?
            ORDER BY v.visit_date DESC, v.id DESC
        ");
        $stmtVisits->execute([$ownerId, $clinicId]);
        $visits = $stmtVisits->fetchAll(PDO::FETCH_ASSOC);

        vr_layout_start('Scheda proprietario - ' . VETROOM_APP_NAME, $user, 'owners');
        ?>
        <h1 class="vr-page-title">Scheda proprietario</h1>
        <p class="vr-page-subtitle">Dettaglio anagrafica e animali collegati.</p>

        <?php if ($msg_error): ?>
            <div class="vr-alert vr-alert-error"><?php echo vr_h($msg_error); ?></div>
        <?php endif; ?>
        <?php if ($msg_success): ?>
            <div class="vr-alert vr-alert-success"><?php echo vr_h($msg_success); ?></div>
        <?php endif; ?>

        <div class="vr-card">
            <div class="vr-card-header">Modifica dati proprietario</div>
            <form method="post">
                <input type="hidden" name="form_action" value="update_owner">
                <?php echo vr_staff_csrf_field(); ?>
                <div class="vr-form-row">
                    <label class="vr-label" for="edit_owner_name">Nome *</label>
                    <input class="vr-input" type="text" id="edit_owner_name" name="name" value="<?php echo vr_h($owner['name'] ?? ''); ?>" required>
                </div>
                <div class="vr-form-row">
                    <label class="vr-label" for="edit_owner_surname">Cognome *</label>
                    <input class="vr-input" type="text" id="edit_owner_surname" name="surname" value="<?php echo vr_h($owner['surname'] ?? ''); ?>" required>
                </div>
                <div class="vr-form-row">
                    <label class="vr-label" for="edit_owner_birth">Data di nascita</label>
                    <input class="vr-input" type="date" id="edit_owner_birth" name="birth_date" value="<?php echo vr_h($owner['birth_date'] ?? ''); ?>">
                </div>
                <div class="vr-form-row">
                    <label class="vr-label" for="edit_owner_cf">Codice fiscale</label>
                    <input class="vr-input" type="text" id="edit_owner_cf" name="fiscal_code" maxlength="16" value="<?php echo vr_h($owner['fiscal_code'] ?? ''); ?>">
                </div>
                <div class="vr-form-row">
                    <label class="vr-label" for="edit_owner_email">Email</label>
                    <input class="vr-input" type="email" id="edit_owner_email" name="email" value="<?php echo vr_h($owner['email'] ?? ''); ?>">
                </div>
                <div class="vr-form-row">
                    <label class="vr-label" for="edit_owner_phone">Telefono</label>
                    <input class="vr-input" type="text" id="edit_owner_phone" name="phone" value="<?php echo vr_h($owner['phone'] ?? ''); ?>">
                </div>

                <div class="vr-form-row">
                    <label class="vr-label">Indirizzo di residenza</label>
                </div>
                <div class="vr-form-row">
                    <input class="vr-input" type="text" name="address_street" placeholder="Via / Piazza" value="<?php echo vr_h($owner['address_street'] ?? ''); ?>">
                </div>
                <div class="vr-form-row" style="display:flex;gap:6px;">
                    <input class="vr-input" type="text" name="address_number" placeholder="Civico" value="<?php echo vr_h($owner['address_number'] ?? ''); ?>">
                    <input class="vr-input" type="text" name="address_zip" placeholder="CAP" value="<?php echo vr_h($owner['address_zip'] ?? ''); ?>">
                </div>
                <div class="vr-form-row" style="display:flex;gap:6px;">
                    <input class="vr-input" type="text" name="address_city" placeholder="Città" value="<?php echo vr_h($owner['address_city'] ?? ''); ?>">
                    <input class="vr-input" type="text" name="address_province" placeholder="Provincia" value="<?php echo vr_h($owner['address_province'] ?? ''); ?>">
                    <input class="vr-input" type="text" name="address_state" placeholder="Stato" value="<?php echo vr_h($owner['address_state'] ?? ''); ?>">
                </div>

                <div class="vr-form-row vr-checkbox-row">
                    <input type="checkbox" id="edit_billing_is_different" name="billing_is_different" value="1" <?php echo !empty($owner['billing_is_different']) ? 'checked' : ''; ?>>
                    <label for="edit_billing_is_different">Indirizzo di fatturazione diverso da residenza</label>
                </div>

                <div class="vr-form-row">
                    <label class="vr-label">Indirizzo di fatturazione (se diverso)</label>
                </div>
                <div class="vr-form-row">
                    <input class="vr-input" type="text" name="billing_street" placeholder="Via / Piazza" value="<?php echo vr_h($owner['billing_street'] ?? ''); ?>">
                </div>
                <div class="vr-form-row" style="display:flex;gap:6px;">
                    <input class="vr-input" type="text" name="billing_number" placeholder="Civico" value="<?php echo vr_h($owner['billing_number'] ?? ''); ?>">
                    <input class="vr-input" type="text" name="billing_zip" placeholder="CAP" value="<?php echo vr_h($owner['billing_zip'] ?? ''); ?>">
                </div>
                <div class="vr-form-row" style="display:flex;gap:6px;">
                    <input class="vr-input" type="text" name="billing_city" placeholder="Città" value="<?php echo vr_h($owner['billing_city'] ?? ''); ?>">
                    <input class="vr-input" type="text" name="billing_province" placeholder="Provincia" value="<?php echo vr_h($owner['billing_province'] ?? ''); ?>">
                    <input class="vr-input" type="text" name="billing_state" placeholder="Stato" value="<?php echo vr_h($owner['billing_state'] ?? ''); ?>">
                </div>

                <button type="submit" class="vr-button">Salva modifiche</button>
            </form>
        </div>

        <div class="vr-card">
            <div class="vr-card-header">Dati anagrafici</div>
            <p style="font-size:13px;margin:0 0 4px;"><strong><?php echo vr_h($owner['surname'] . ' ' . $owner['name']); ?></strong></p>
            <p style="font-size:12px;margin:0 0 4px;">CF: <strong><?php echo vr_h($owner['fiscal_code']); ?></strong></p>
            <p style="font-size:12px;margin:0 0 4px;">
                Email: <?php echo $owner['email'] ? vr_h($owner['email']) : '<em>n.d.</em>'; ?> –
                Tel: <?php echo $owner['phone'] ? vr_h($owner['phone']) : '<em>n.d.</em>'; ?>
            </p>
            <p style="font-size:12px;margin:0 0 4px;">
                Residenza:
                <?php
                $adr = trim(($owner['address_street'] ?? '') . ' ' . ($owner['address_number'] ?? ''));
                $city = trim(($owner['address_zip'] ?? '') . ' ' . ($owner['address_city'] ?? ''));
                $provState = trim(($owner['address_province'] ?? '') . ' ' . ($owner['address_state'] ?? ''));
                echo $adr ? vr_h($adr) : '<em>n.d.</em>';
                if ($city) echo ', ' . vr_h($city);
                if ($provState) echo ' (' . vr_h($provState) . ')';
                ?>
            </p>
            <?php if (!empty($owner['billing_is_different'])): ?>
                <p style="font-size:12px;margin:0 0 4px;">
                    Fatturazione:
                    <?php
                    $badr = trim(($owner['billing_street'] ?? '') . ' ' . ($owner['billing_number'] ?? ''));
                    $bcity = trim(($owner['billing_zip'] ?? '') . ' ' . ($owner['billing_city'] ?? ''));
                    $bprovState = trim(($owner['billing_province'] ?? '') . ' ' . ($owner['billing_state'] ?? ''));
                    echo $badr ? vr_h($badr) : '<em>n.d.</em>';
                    if ($bcity) echo ', ' . vr_h($bcity);
                    if ($bprovState) echo ' (' . vr_h($bprovState) . ')';
                    ?>
                </p>
            <?php endif; ?>
        </div>

        <div class="vr-card">
            <div class="vr-card-header">Animali di questo proprietario</div>
            <?php if (!$pets): ?>
                <p style="font-size:13px;margin:0;">Nessun animale collegato.</p>
            <?php else: ?>
                <table class="vr-table">
                    <thead>
                    <tr>
                        <th>Nome</th>
                        <th>Specie</th>
                        <th>Razza</th>
                        <th>Azioni</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($pets as $p): ?>
                        <tr>
                            <td><?php echo vr_h($p['name']); ?></td>
                            <td><?php echo vr_h($p['species']); ?></td>
                            <td><?php echo vr_h($p['breed']); ?></td>
                            <td>
                                <a href="index.php?page=pet_detail&id=<?php echo (int)$p['id']; ?>" class="vr-button" style="font-size:11px;padding:3px 10px;">Scheda animale</a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>

        <div class="vr-card">
            <div class="vr-card-header">Storico visite (tutti gli animali)</div>
            <?php if (vr_policy_role($user) === 'SECRETARY'): ?>
                <p style="font-size:13px;margin:0;">Le visite cliniche sono accessibili solo ai veterinari.</p>
            <?php elseif (!$visits): ?>
                <p style="font-size:13px;margin:0;">Ancora nessuna visita registrata.</p>
            <?php else: ?>
                <table class="vr-table">
                    <thead>
                    <tr>
                        <th>Data</th>
                        <th>Tipo</th>
                        <th>Animale</th>
                        <th>Diagnosi</th>
                        <th>Azioni</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($visits as $v): ?>
                        <tr>
                            <td><?php echo vr_h($v['visit_date']); ?></td>
                            <td><?php
                                if ($v['visit_kind'] === 'OFTALMO') {
                                    echo 'Oftalmologica';
                                } elseif ($v['visit_kind'] === 'CUSTOM') {
                                    echo vr_h($v['visit_form_name'] ?: 'Visita personalizzata');
                                } else {
                                    echo 'Clinica';
                                }
                            ?></td>
                            <td><?php echo vr_h($v['pet_name']); ?></td>
                            <td><?php echo vr_h($v['diagnosis']); ?></td>
                            <td>
                                <a href="index.php?page=visit_show&id=<?php echo (int)$v['id']; ?>" class="vr-button" style="font-size:11px;padding:3px 10px;">Apri scheda</a>
                            
                                <a href="index.php?page=visit_edit&id=<?php echo (int)$v['id']; ?>" class="vr-button vr-button-secondary" style="font-size:11px;padding:3px 8px;margin-left:4px;">Modifica</a>
                                <form method="post" action="index.php?page=owner_detail&id=<?php echo (int)$ownerId; ?>" style="display:inline;margin-left:4px;" onsubmit="return confirm('Confermi l\'eliminazione di questa visita?');">
                                    <input type="hidden" name="form_action" value="delete_visit">
                                    <?php echo vr_staff_csrf_field(); ?>
                                    <input type="hidden" name="visit_id" value="<?php echo (int)$v['id']; ?>">
                                    <button type="submit" class="vr-button vr-button-danger vr-icon-submit"><img class="vr-action-icon vr-btn-ico" src="public/icons/delete_visit.png" alt="Elimina"><span class="vr-btn-label">Elimina</span></button>
                                </form>
    </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
        <?php
        vr_layout_end();
        break;
    }

    /* ANIMALI */
    case 'pets': {
        $pets_error = '';
        $pets_success = '';

        if (vr_policy_is_admin($user)) {
            $stmtOwnersAll = $db->prepare("SELECT * FROM owners WHERE clinic_id = ? ORDER BY surname, name");
        } else {
            $stmtOwnersAll = $db->prepare("SELECT o.* FROM owners o JOIN vet_owner_relations r ON r.owner_id=o.id AND r.clinic_id=o.clinic_id WHERE o.clinic_id = ? AND r.status='active' ORDER BY o.surname, o.name");
        }
        $stmtOwnersAll->execute([$clinicId]);
        $ownersAll = $stmtOwnersAll->fetchAll(PDO::FETCH_ASSOC);

        if ($_SERVER['REQUEST_METHOD'] === 'POST' && ( $_POST['form_action'] ?? '' ) === 'delete_pet') {
            $pet_id = (int)($_POST['pet_id'] ?? 0);
            if ($pet_id <= 0) {
                $pets_error = 'Animale non valido.';
            } else {
                if (vr_policy_is_admin($user)) {
                    $stmtCheckPet = $db->prepare("SELECT id FROM pets WHERE id = ? AND clinic_id = ?");
                } else {
                    $stmtCheckPet = $db->prepare("SELECT p.id FROM pets p JOIN owners o ON o.id=p.owner_id JOIN vet_owner_relations r ON r.owner_id=o.id AND r.clinic_id=o.clinic_id WHERE p.id = ? AND p.clinic_id = ? AND r.status='active' LIMIT 1");
                }
                $stmtCheckPet->execute([$pet_id, $clinicId]);
                if (!$stmtCheckPet->fetchColumn()) {
                    $pets_error = 'Animale non trovato.';
                } else {
                    // ON DELETE CASCADE su appointments
                    $stmtDelPet = $db->prepare("DELETE FROM pets WHERE id = ? AND clinic_id = ?");
                    $stmtDelPet->execute([$pet_id, $clinicId]);
                    $pets_success = 'Animale eliminato (con eventuali appuntamenti collegati).';
                }
            }
        }

        if ($_SERVER['REQUEST_METHOD'] === 'POST' && ( $_POST['form_action'] ?? '' ) === 'create_pet') {
            $owner_id = (int)($_POST['owner_id'] ?? 0);
            $name = trim($_POST['name'] ?? '');
            $species = trim($_POST['species'] ?? '');
            $breed = trim($_POST['breed'] ?? '');
            $birth_date = trim($_POST['birth_date'] ?? '');
            $sex = trim($_POST['sex'] ?? '');
            $neuter_status = trim($_POST['neuter_status'] ?? '');
            $weight_kg = $_POST['weight_kg'] !== '' ? (float)$_POST['weight_kg'] : null;
            $microchip = trim($_POST['microchip'] ?? '');
            $notes = trim($_POST['notes'] ?? '');

            if ($owner_id <= 0 || $name === '' || $species === '') {
                $pets_error = 'Proprietario, nome e specie sono obbligatori.';
            } else {
                $ownerOk = false;
                foreach ($ownersAll as $ow) {
                    if ((int)$ow['id'] === $owner_id) { $ownerOk = true; break; }
                }
                if (!$ownerOk) {
                    $pets_error = 'Proprietario non valido.';
                } else {
                    try {
                        $now = vr_now_iso();
                        $stmtIns = $db->prepare("
                            INSERT INTO pets (
                                clinic_id, owner_id, name, species, breed, birth_date, sex,
                                neuter_status, weight_kg, microchip, notes, created_at, updated_at
                            ) VALUES (
                                ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?
                            )
                        ");
                        $stmtIns->execute([
                            $clinicId, $owner_id, $name, $species, $breed, $birth_date, $sex,
                            $neuter_status, $weight_kg, $microchip, $notes, $now, $now
                        ]);
                        $pets_success = 'Animale creato correttamente.';
                    } catch (Exception $e) {
                        $pets_error = 'Errore durante il salvataggio: ' . $e->getMessage();
                    }
                }
            }
        }

        $q = trim($_GET['q'] ?? '');
        if ($q !== '') {
            $like = '%' . $q . '%';
            if (vr_policy_is_admin($user)) {
                $stmtPets = $db->prepare("
                    SELECT p.*, o.name AS owner_name, o.surname AS owner_surname
                    FROM pets p
                    JOIN owners o ON p.owner_id = o.id
                    WHERE p.clinic_id = ?
                      AND (p.name LIKE ? OR p.species LIKE ? OR o.surname LIKE ? OR o.name LIKE ?)
                    ORDER BY p.name
                ");
                $stmtPets->execute([$clinicId, $like, $like, $like, $like]);
            } else {
                $stmtPets = $db->prepare("
                    SELECT p.*, o.name AS owner_name, o.surname AS owner_surname
                    FROM pets p
                    JOIN owners o ON p.owner_id = o.id
                    JOIN vet_owner_relations r ON r.owner_id=o.id AND r.clinic_id=o.clinic_id
                    WHERE p.clinic_id = ?
                      AND r.status = 'active'
                      AND (p.name LIKE ? OR p.species LIKE ? OR o.surname LIKE ? OR o.name LIKE ?)
                    ORDER BY p.name
                ");
                $stmtPets->execute([$clinicId, $like, $like, $like, $like]);
            }
        } else {
            if (vr_policy_is_admin($user)) {
                $stmtPets = $db->prepare("
                    SELECT p.*, o.name AS owner_name, o.surname AS owner_surname
                    FROM pets p
                    JOIN owners o ON p.owner_id = o.id
                    WHERE p.clinic_id = ?
                    ORDER BY p.name
                ");
                $stmtPets->execute([$clinicId]);
            } else {
                $stmtPets = $db->prepare("
                    SELECT p.*, o.name AS owner_name, o.surname AS owner_surname
                    FROM pets p
                    JOIN owners o ON p.owner_id = o.id
                    JOIN vet_owner_relations r ON r.owner_id=o.id AND r.clinic_id=o.clinic_id
                    WHERE p.clinic_id = ?
                      AND r.status = 'active'
                    ORDER BY p.name
                ");
                $stmtPets->execute([$clinicId]);
            }
        }
        $pets = $stmtPets->fetchAll(PDO::FETCH_ASSOC);

        vr_layout_start('Animali - ' . VETROOM_APP_NAME, $user, 'pets');
        ?>
        <h1 class="vr-page-title">Animali</h1>
        <p class="vr-page-subtitle">Pazienti con dati completi.</p>

        <div class="vr-card">
            <div class="vr-card-header">Ricerca</div>
            <form method="get">
                <input type="hidden" name="page" value="pets">
                <div class="vr-form-row" style="display:flex;gap:6px;">
                    <input class="vr-input" type="text" name="q" placeholder="Cerca per nome animale, specie, proprietario..." value="<?php echo vr_h($q); ?>">
                    <button class="vr-button vr-button-secondary" type="submit">Cerca</button>
                </div>
            </form>
        </div>

        <div class="vr-card vr-collapsible" id="new_pet">
            <div class="vr-card-header">Nuovo animale</div>
            <?php if ($pets_error): ?>
                <div class="vr-alert vr-alert-error"><?php echo vr_h($pets_error); ?></div>
            <?php endif; ?>
            <?php if ($pets_success): ?>
                <div class="vr-alert vr-alert-success"><?php echo vr_h($pets_success); ?></div>
            <?php endif; ?>

            <?php if (!$ownersAll): ?>
                <p style="font-size:13px;margin:0;">Per inserire un animale devi prima creare un proprietario.</p>
            <?php else: ?>
                <form method="post">
                    <input type="hidden" name="form_action" value="create_pet">
                    <?php echo vr_staff_csrf_field(); ?>
                    <div class="vr-form-row">
                        <label class="vr-label" for="owner_id_pet">Proprietario</label>
                        <select class="vr-select" id="owner_id_pet" name="owner_id" required>
                            <option value="">Seleziona...</option>
                            <?php foreach ($ownersAll as $ow): ?>
                                <option value="<?php echo (int)$ow['id']; ?>"><?php echo vr_h($ow['surname'] . ' ' . $ow['name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="vr-form-row">
                        <label class="vr-label" for="pet_name">Nome animale</label>
                        <input class="vr-input" type="text" id="pet_name" name="name" required>
                    </div>
                    <div class="vr-form-row">
                        <label class="vr-label" for="species">Specie</label>
                        <input class="vr-input" type="text" id="species" name="species" placeholder="Es. cane, gatto..." required>
                    </div>
                    <div class="vr-form-row">
                        <label class="vr-label" for="breed">Razza</label>
                        <input class="vr-input" type="text" id="breed" name="breed">
                    </div>
                    <div class="vr-form-row">
                        <label class="vr-label" for="birth_date_pet">Data di nascita</label>
                        <input class="vr-input" type="date" id="birth_date_pet" name="birth_date">
                    </div>
                    <div class="vr-form-row">
                        <label class="vr-label" for="sex">Sesso</label>
                        <select class="vr-select" id="sex" name="sex">
                            <option value="">N/D</option>
                            <option value="M">Maschio</option>
                            <option value="F">Femmina</option>
                        </select>
                    </div>
                    <div class="vr-form-row">
                        <label class="vr-label" for="neuter_status">Stato riproduttivo</label>
                        <select class="vr-select" id="neuter_status" name="neuter_status">
                            <option value="">N/D</option>
                            <option value="INTERO">Intero</option>
                            <option value="STERILIZZATO">Castrato/Sterilizzato</option>
                        </select>
                    </div>
                    <div class="vr-form-row">
                        <label class="vr-label" for="weight_kg">Peso (kg)</label>
                        <input class="vr-input" type="number" step="0.1" id="weight_kg" name="weight_kg">
                    </div>
                    <div class="vr-form-row">
                        <label class="vr-label" for="microchip">Microchip</label>
                        <input class="vr-input" type="text" id="microchip" name="microchip">
                    </div>
                    <div class="vr-form-row">
                        <label class="vr-label" for="notes_pet">Note</label>
                        <textarea class="vr-textarea" id="notes_pet" name="notes"></textarea>
                    </div>
                    <button type="submit" class="vr-button vr-icon-submit"><img class="vr-action-icon vr-btn-ico" src="public/icons/create_pet.png" alt="Salva animale"><span class="vr-btn-label">Salva animale</span></button>
                </form>
            <?php endif; ?>
        </div>

        <div class="vr-card">
            <div class="vr-card-header">Elenco animali</div>
            <?php if (!$pets): ?>
                <p style="font-size:13px;margin:0;">Nessun animale trovato.</p>
            <?php else: ?>
                <table class="vr-table">
                    <thead>
                    <tr>
                        <th>Nome</th>
                        <th>Specie</th>
                        <th>Proprietario</th>
                        <th>Sesso</th>
                        <th>Stato riproduttivo</th>
                        <th>Azioni</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($pets as $p): ?>
                        <tr>
                            <td><?php echo vr_h($p['name']); ?></td>
                            <td><?php echo vr_h($p['species']); ?></td>
                            <td><?php echo vr_h($p['owner_surname'] . ' ' . $p['owner_name']); ?></td>
                            <td><?php echo vr_h($p['sex']); ?></td>
                            <td><?php echo vr_h($p['neuter_status']); ?></td>
                            <td>
                                <a href="index.php?page=pet_detail&id=<?php echo (int)$p['id']; ?>" class="vr-button" style="font-size:11px;padding:3px 10px;">Scheda paziente</a>
                            <form method="post" style="display:inline;margin-left:4px;" onsubmit="return confirm('Eliminare questo animale e gli appuntamenti collegati?');">
                                <input type="hidden" name="form_action" value="delete_pet">
                                <?php echo vr_staff_csrf_field(); ?>
                                <input type="hidden" name="pet_id" value="<?php echo (int)$p['id']; ?>">
                                <button type="submit" class="vr-button vr-button-danger vr-icon-submit"><img class="vr-action-icon vr-btn-ico" src="public/icons/delete_pet.png" alt="Elimina"><span class="vr-btn-label">Elimina</span></button>
                            </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
        <?php
        vr_layout_end();
        break;
    }

    case 'team': {
        if (!vr_policy_is_admin($user)) {
            vr_require_permission();
        }

        // Handle team actions
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $fa = (string)($_POST['form_action'] ?? '');
            if ($fa === 'create_staff_invite') {
                $email = strtolower(trim((string)($_POST['email'] ?? '')));
                $role = strtoupper(trim((string)($_POST['role'] ?? '')));
                if (!in_array($role, ['VET','SECRETARY'], true)) {
                    $role = 'SECRETARY';
                }
                if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    $_SESSION['flash'] = ['type'=>'error','msg'=>'Email non valida.'];
                    header('Location: index.php?page=team');
                    exit;
                }

                // Check clinic quotas
                $cl = $db->prepare("SELECT max_vets, max_secretaries FROM clinics WHERE id=? LIMIT 1");
                $cl->execute([$clinicId]);
                $c = $cl->fetch(PDO::FETCH_ASSOC) ?: ['max_vets'=>1,'max_secretaries'=>0];
                $maxVets = max(1, (int)($c['max_vets'] ?? 1));
                $maxSecs = max(0, (int)($c['max_secretaries'] ?? 0));

                // Count staff memberships (user_clinic is the source of truth for multi-clinic staff)
                $usedVets = (int)($db->query("SELECT COUNT(1)
                                             FROM user_clinic uc
                                             JOIN users u ON u.id = uc.user_id
                                             WHERE uc.clinic_id=".$clinicId." AND uc.is_active=1 AND u.is_active=1 AND UPPER(uc.role) IN ('CHIEF','VET')")->fetchColumn() ?: 0);
                $usedSecs = (int)($db->query("SELECT COUNT(1)
                                             FROM user_clinic uc
                                             JOIN users u ON u.id = uc.user_id
                                             WHERE uc.clinic_id=".$clinicId." AND uc.is_active=1 AND u.is_active=1 AND UPPER(uc.role) IN ('SECRETARY','STAFF')")->fetchColumn() ?: 0);

                if ($role === 'VET' && $usedVets >= $maxVets) {
                    $_SESSION['flash'] = ['type'=>'error','msg'=>'Quota veterinari raggiunta. Richiedi un aumento quote alla piattaforma.'];
                    header('Location: index.php?page=team');
                    exit;
                }
                if ($role === 'SECRETARY' && $usedSecs >= $maxSecs) {
                    $_SESSION['flash'] = ['type'=>'error','msg'=>'Quota segreteria raggiunta. Richiedi un aumento quote alla piattaforma.'];
                    header('Location: index.php?page=team');
                    exit;
                }

                // Create invitation (token stored as sha256 hash)
                $token = bin2hex(random_bytes(16));
                $hash = hash('sha256', $token);
                $expiresAt = (new DateTimeImmutable('+7 days'))->format('c');
                $now = vr_now_iso();
                $db->prepare("INSERT INTO staff_invitations (clinic_id,email,role,token_hash,expires_at,created_by_user_id,created_at) VALUES (?,?,?,?,?,?,?)")
                   ->execute([$clinicId,$email,$role,$hash,$expiresAt,(int)$user['id'],$now]);

                // Show invite link once (token not stored)
                // Note: we do not store the raw token in DB, so show the link only once.
                $link = vr_app_url('staff_register.php?token=' . urlencode($token));

                // Send invite email (best-effort). The link is also shown for manual copy.
                try {
                    $cname = (string)($db->query("SELECT name FROM clinics WHERE id=".(int)$clinicId." LIMIT 1")->fetchColumn() ?: '');
                    $roleLabel = ($role === 'VET') ? 'Veterinario' : 'Segreteria';
                    $subject = 'Invito VetRoom — ' . $roleLabel;
                    $body = "Ciao,

" .
        "Sei stato invitato nello staff della clinica \"{$cname}\" come {$roleLabel}.
" .
        "Link (valido 7 giorni):
{$link}

" .
        "— VetRoom
";
                    $sent = vr_mail_send($email, $subject, $body);
                } catch (Throwable $t) {
                    $sent = false;
                }

                if (!empty($sent)) {
                    $_SESSION['flash'] = ['type'=>'success','msg'=>'Invito creato e inviato via email. Link: ' . $link];
                } else {
                    $_SESSION['flash'] = ['type'=>'error','msg'=>'Invito creato ma email non inviata (config mail). Copia il link: ' . $link];
                }
                header('Location: index.php?page=team');
                exit;
            }

            if ($fa === 'revoke_staff_invite') {
                $invId = (int)($_POST['invite_id'] ?? 0);
                if ($invId > 0) {
                    $db->prepare("UPDATE staff_invitations SET revoked_at=? WHERE id=? AND clinic_id=? AND used_at IS NULL")
                       ->execute([vr_now_iso(),$invId,$clinicId]);
                    $_SESSION['flash'] = ['type'=>'success','msg'=>'Invito revocato.'];
                }
                header('Location: index.php?page=team');
                exit;
            }

            if ($fa === 'toggle_staff_active') {
                $uid = (int)($_POST['user_id'] ?? 0);
                if ($uid > 0) {
                    $row = $db->prepare("SELECT u.id, u.name, u.is_active AS user_active, uc.role AS role, uc.is_active AS membership_active
                                         FROM user_clinic uc
                                         JOIN users u ON u.id = uc.user_id
                                         WHERE uc.user_id=? AND uc.clinic_id=?
                                         LIMIT 1");
                    $row->execute([$uid,$clinicId]);
                    $u = $row->fetch(PDO::FETCH_ASSOC);
                    if ($u) {
                        $role = strtoupper((string)($u['role'] ?? ''));
                        if ($role === 'CHIEF') {
                            $_SESSION['flash'] = ['type'=>'error','msg'=>'Non puoi disattivare il CHIEF.'];
                        } else {
                            $currentMem = (int)($u['membership_active'] ?? 0);
                            $newMem = ($currentMem === 1) ? 0 : 1;

                            // When re-activating, re-check clinic quotas.
                            if ($newMem === 1) {
                                $cl = $db->prepare("SELECT max_vets, max_secretaries FROM clinics WHERE id=? LIMIT 1");
                                $cl->execute([$clinicId]);
                                $c = $cl->fetch(PDO::FETCH_ASSOC) ?: ['max_vets'=>1,'max_secretaries'=>0];
                                $maxVets = max(1, (int)($c['max_vets'] ?? 1));
                                $maxSecs = max(0, (int)($c['max_secretaries'] ?? 0));

                                $usedVets = (int)($db->query("SELECT COUNT(1)
                                                             FROM user_clinic uc
                                                             JOIN users u ON u.id = uc.user_id
                                                             WHERE uc.clinic_id=".$clinicId." AND uc.is_active=1 AND u.is_active=1 AND UPPER(uc.role) IN ('CHIEF','VET')")->fetchColumn() ?: 0);
                                $usedSecs = (int)($db->query("SELECT COUNT(1)
                                                             FROM user_clinic uc
                                                             JOIN users u ON u.id = uc.user_id
                                                             WHERE uc.clinic_id=".$clinicId." AND uc.is_active=1 AND u.is_active=1 AND UPPER(uc.role) IN ('SECRETARY','STAFF')")->fetchColumn() ?: 0);

                                if ($role === 'VET' && $usedVets >= $maxVets) {
                                    $_SESSION['flash'] = ['type'=>'error','msg'=>'Quota veterinari raggiunta.'];
                                    header('Location: index.php?page=team');
                                    exit;
                                }
                                if (in_array($role, ['SECRETARY','STAFF'], true) && $usedSecs >= $maxSecs) {
                                    $_SESSION['flash'] = ['type'=>'error','msg'=>'Quota segreteria raggiunta.'];
                                    header('Location: index.php?page=team');
                                    exit;
                                }
                            }

                            $db->prepare("UPDATE user_clinic SET is_active=?, updated_at=? WHERE user_id=? AND clinic_id=?")
                               ->execute([$newMem, vr_now_iso(), $uid, $clinicId]);
                            $_SESSION['flash'] = ['type'=>'success','msg'=>'Membership aggiornata.'];
                        }
                    }
                }
                header('Location: index.php?page=team');
                exit;
            }

            if ($fa === 'request_quota_increase') {
                $reqVets = max(1, (int)($_POST['requested_max_vets'] ?? 1));
                $reqSecs = max(0, (int)($_POST['requested_max_secretaries'] ?? 0));
                $db->prepare("INSERT INTO quota_requests (clinic_id,requested_max_vets,requested_max_secretaries,status,created_by_user_id,created_at) VALUES (?,?,?,?,?,?)")
                   ->execute([$clinicId,$reqVets,$reqSecs,'NEW',(int)$user['id'],vr_now_iso()]);
                $_SESSION['flash'] = ['type'=>'success','msg'=>'Richiesta inviata alla piattaforma.'];
                header('Location: index.php?page=team');
                exit;
            }
        }

        // Data for view
        $clinic = $db->prepare("SELECT name, max_vets, max_secretaries FROM clinics WHERE id=? LIMIT 1");
        $clinic->execute([$clinicId]);
        $clinicRow = $clinic->fetch(PDO::FETCH_ASSOC) ?: ['name'=>'','max_vets'=>1,'max_secretaries'=>0];

        $maxVets = max(1, (int)($clinicRow['max_vets'] ?? 1));
        $maxSecs = max(0, (int)($clinicRow['max_secretaries'] ?? 0));
        // Count staff memberships (user_clinic is the source of truth)
        $usedVets = (int)($db->query("SELECT COUNT(1)
                                     FROM user_clinic uc
                                     JOIN users u ON u.id = uc.user_id
                                     WHERE uc.clinic_id=".$clinicId." AND uc.is_active=1 AND u.is_active=1 AND UPPER(uc.role) IN ('CHIEF','VET')")->fetchColumn() ?: 0);
        $usedSecs = (int)($db->query("SELECT COUNT(1)
                                     FROM user_clinic uc
                                     JOIN users u ON u.id = uc.user_id
                                     WHERE uc.clinic_id=".$clinicId." AND uc.is_active=1 AND u.is_active=1 AND UPPER(uc.role) IN ('SECRETARY','STAFF')")->fetchColumn() ?: 0);

        // Team is considered "locked" when the clinic is a single-vet setup (default quotas)
        // and there are no additional staff members besides the CHIEF.
        $extraStaffCount = max(0, ($usedVets + $usedSecs) - 1);
        $teamLocked = ($extraStaffCount === 0 && $maxVets <= 1 && $maxSecs <= 0);

        $staffStmt = $db->prepare("SELECT u.id, u.name, u.email,
                                          uc.role,
                                          u.is_active AS user_active,
                                          uc.is_active AS membership_active,
                                          u.created_at
                                   FROM user_clinic uc
                                   JOIN users u ON u.id = uc.user_id
                                   WHERE uc.clinic_id=?
                                   ORDER BY CASE UPPER(uc.role) WHEN 'CHIEF' THEN 0 WHEN 'VET' THEN 1 WHEN 'SECRETARY' THEN 2 WHEN 'STAFF' THEN 2 ELSE 3 END, u.id ASC");
        $staffStmt->execute([$clinicId]);
        $staff = $staffStmt->fetchAll(PDO::FETCH_ASSOC);

        $invStmt = $db->prepare("SELECT id,email,role,expires_at,created_at FROM staff_invitations WHERE clinic_id=? AND used_at IS NULL AND revoked_at IS NULL ORDER BY id DESC");
        $invStmt->execute([$clinicId]);
        $pendingInv = $invStmt->fetchAll(PDO::FETCH_ASSOC);

        $reqStmt = $db->prepare("SELECT * FROM quota_requests WHERE clinic_id=? ORDER BY id DESC LIMIT 5");
        $reqStmt->execute([$clinicId]);
        $quotaReqs = $reqStmt->fetchAll(PDO::FETCH_ASSOC);

        vr_layout_start('Team - ' . VETROOM_APP_NAME, $user, $page);
        ?>
        <div class="vr-card">
            <div class="vr-card-header">
                <h2 class="vr-card-title">Team</h2>
                <div class="vr-card-sub">Gestisci veterinari e segreteria della tua struttura. Le quote vengono impostate da ADMIN (piattaforma).</div>
            </div>
            <div class="vr-card-body">
                <?php if (!empty($_SESSION['flash']) && is_array($_SESSION['flash'])): ?>
                    <?php $ft = (string)($_SESSION['flash']['type'] ?? 'success'); $fm = (string)($_SESSION['flash']['msg'] ?? ''); unset($_SESSION['flash']); ?>
                    <div class="vr-alert <?php echo ($ft === 'error') ? 'vr-alert-error' : 'vr-alert-success'; ?>" style="margin-bottom:12px;">
                        <?php echo vr_h($fm); ?>
                    </div>
                <?php endif; ?>

                <?php if ($teamLocked): ?>
                    <div class="vr-panel" style="margin-bottom:14px;">
                        <div style="font-weight:800;margin-bottom:6px;">Sblocca il tuo team</div>
                        <div style="color:#444;line-height:1.4;">
                            Per aggiungere segretarie o altri veterinari, contattaci: <b>info@vetroom.it</b>.
                        </div>
                        <div style="margin-top:10px;">
                            <button type="button" class="vr-btn" onclick="(function(){var f=document.getElementById('vr_unlock_team_form'); if(!f) return; f.style.display = (f.style.display==='none' || !f.style.display) ? 'block' : 'none';})();">Richiedi sblocco</button>
                        </div>
                        <div id="vr_unlock_team_form" style="display:none;margin-top:12px;">
                            <form method="post" action="index.php?page=team" style="margin:0;">
                                <?php echo vr_staff_csrf_field(); ?>
                                <input type="hidden" name="form_action" value="request_quota_increase">
                                <div class="vr-grid" style="grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:10px;">
                                    <div>
                                        <label style="display:block;margin-top:0;">Veterinari (max)</label>
                                        <input class="vr-input" type="number" min="1" name="requested_max_vets" value="<?php echo (int)max($maxVets, 2); ?>">
                                    </div>
                                    <div>
                                        <label style="display:block;margin-top:0;">Segretarie (max)</label>
                                        <input class="vr-input" type="number" min="0" name="requested_max_secretaries" value="<?php echo (int)max($maxSecs, 1); ?>">
                                    </div>
                                </div>
                                <button class="vr-btn" type="submit" style="margin-top:10px;">Invia richiesta</button>
                                <div style="margin-top:8px;color:#666;font-size:13px;">La richiesta verrà valutata da ADMIN (piattaforma).</div>
                            </form>
                        </div>
                    </div>

                    <div class="vr-panel" style="margin-bottom:14px;">
                        <div style="font-weight:700;margin-bottom:6px;">Quote attuali</div>
                        <div>Veterinari: <b><?php echo (int)$usedVets; ?></b> / <b><?php echo (int)$maxVets; ?></b></div>
                        <div>Segreteria: <b><?php echo (int)$usedSecs; ?></b> / <b><?php echo (int)$maxSecs; ?></b></div>
                    </div>

                    <div class="vr-panel">
                        <div style="font-weight:700;margin-bottom:6px;">Storico richieste quote</div>
                        <?php if (!$quotaReqs): ?>
                            <div style="color:#666;">Nessuna richiesta inviata.</div>
                        <?php else: ?>
                            <table class="vr-table" style="width:100%;">
                                <thead><tr><th>Data</th><th>Richiesta</th><th>Stato</th><th>Note admin</th></tr></thead>
                                <tbody>
                                <?php foreach ($quotaReqs as $qr): ?>
                                    <tr>
                                        <td><?php echo vr_h(vr_fmt_date((string)$qr['created_at'])); ?></td>
                                        <td>VET: <?php echo (int)$qr['requested_max_vets']; ?>, SEG: <?php echo (int)$qr['requested_max_secretaries']; ?></td>
                                        <td><?php echo vr_h((string)$qr['status']); ?></td>
                                        <td><?php echo vr_h((string)($qr['admin_note'] ?? '')); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        <?php endif; ?>
                    </div>

                <?php else: ?>
                <div class="vr-grid" style="grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:14px;">
                    <div class="vr-panel">
                        <div style="font-weight:700;margin-bottom:6px;">Quote attuali</div>
                        <div>Veterinari: <b><?php echo (int)$usedVets; ?></b> / <b><?php echo (int)$maxVets; ?></b></div>
                        <div>Segreteria: <b><?php echo (int)$usedSecs; ?></b> / <b><?php echo (int)$maxSecs; ?></b></div>
                    </div>
                    <div class="vr-panel">
                        <div style="font-weight:700;margin-bottom:6px;">Richiedi aumento quote</div>
                        <form method="post" action="index.php?page=team">
                            <?php echo vr_staff_csrf_field(); ?>
                            <input type="hidden" name="form_action" value="request_quota_increase">
                            <label style="display:block;margin-top:6px;">Max veterinari richiesti</label>
                            <input class="vr-input" type="number" min="1" name="requested_max_vets" value="<?php echo (int)$maxVets; ?>">
                            <label style="display:block;margin-top:6px;">Max segreterie richieste</label>
                            <input class="vr-input" type="number" min="0" name="requested_max_secretaries" value="<?php echo (int)$maxSecs; ?>">
                            <button class="vr-btn" type="submit" style="margin-top:10px;">Invia richiesta</button>
                        </form>
                    </div>
                </div>

                <hr style="margin:18px 0;">

                <div class="vr-grid" style="grid-template-columns:repeat(auto-fit,minmax(320px,1fr));gap:14px;">
                    <div class="vr-panel">
                        <div style="font-weight:700;margin-bottom:6px;">Invita membro</div>
                        <form method="post" action="index.php?page=team">
                            <?php echo vr_staff_csrf_field(); ?>
                            <input type="hidden" name="form_action" value="create_staff_invite">
                            <label>Email</label>
                            <input class="vr-input" name="email" type="email" required>
                            <label>Ruolo</label>
                            <select class="vr-input" name="role">
                                <option value="SECRETARY">Segreteria</option>
                                <option value="VET">Veterinario</option>
                            </select>
                            <button class="vr-btn" type="submit" style="margin-top:10px;">Crea invito</button>
                            <div style="margin-top:8px;color:#666;font-size:13px;">Il link viene mostrato una sola volta dopo la creazione.</div>
                        </form>
                    </div>

                    <div class="vr-panel">
                        <div style="font-weight:700;margin-bottom:6px;">Inviti in attesa</div>
                        <?php if (!$pendingInv): ?>
                            <div style="color:#666;">Nessun invito in attesa.</div>
                        <?php else: ?>
                            <table class="vr-table" style="width:100%;">
                                <thead><tr><th>Email</th><th>Ruolo</th><th>Scadenza</th><th></th></tr></thead>
                                <tbody>
                                <?php foreach ($pendingInv as $pi): ?>
                                    <tr>
                                        <td><?php echo vr_h((string)$pi['email']); ?></td>
                                        <td><?php echo vr_h((string)$pi['role']); ?></td>
                                        <td><?php echo vr_h(vr_fmt_date((string)$pi['expires_at'])); ?></td>
                                        <td style="text-align:right;">
                                            <form method="post" action="index.php?page=team" style="display:inline;">
                                                <?php echo vr_staff_csrf_field(); ?>
                                                <input type="hidden" name="form_action" value="revoke_staff_invite">
                                                <input type="hidden" name="invite_id" value="<?php echo (int)$pi['id']; ?>">
                                                <button class="vr-btn vr-btn-secondary" type="submit">Revoca</button>
                                            </form>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        <?php endif; ?>
                    </div>
                </div>

                <hr style="margin:18px 0;">

                <div class="vr-panel">
                    <div style="font-weight:700;margin-bottom:6px;">Staff</div>
                    <table class="vr-table" style="width:100%;">
                        <thead><tr><th>Nome</th><th>Email</th><th>Ruolo</th><th>Stato</th><th></th></tr></thead>
                        <tbody>
                        <?php foreach ($staff as $su):
                            $r = strtoupper((string)($su['role'] ?? ''));
                            $userActive = ((int)($su['user_active'] ?? 0) === 1);
                            $memActive  = ((int)($su['membership_active'] ?? 0) === 1);
                            $active = ($userActive && $memActive);
                            ?>
                            <tr>
                                <td><?php echo vr_h((string)$su['name']); ?></td>
                                <td><?php echo vr_h((string)$su['email']); ?></td>
                                <td><?php echo vr_h($r); ?></td>
                                <td>
                                    <?php
                                    if (!$userActive) {
                                        echo '<span class="vr-badge">SOSPESO (ADMIN)</span>';
                                    } elseif ($memActive) {
                                        echo '<span class="vr-badge vr-badge-success">ATTIVO</span>';
                                    } else {
                                        echo '<span class="vr-badge">DISATTIVO</span>';
                                    }
                                    ?>
                                </td>
                                <td style="text-align:right;">
                                    <?php if ($r !== 'CHIEF' && $userActive): ?>
                                        <form method="post" action="index.php?page=team" style="display:inline;">
                                            <?php echo vr_staff_csrf_field(); ?>
                                            <input type="hidden" name="form_action" value="toggle_staff_active">
                                            <input type="hidden" name="user_id" value="<?php echo (int)$su['id']; ?>">
                                            <button class="vr-btn vr-btn-secondary" type="submit"><?php echo $active ? 'Disattiva' : 'Attiva'; ?></button>
                                        </form>
                                    <?php else: ?>
                                        <span style="color:#666;">—</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <hr style="margin:18px 0;">
                <div class="vr-panel">
                    <div style="font-weight:700;margin-bottom:6px;">Storico richieste quote</div>
                    <?php if (!$quotaReqs): ?>
                        <div style="color:#666;">Nessuna richiesta inviata.</div>
                    <?php else: ?>
                        <table class="vr-table" style="width:100%;">
                            <thead><tr><th>Data</th><th>Richiesta</th><th>Stato</th><th>Note admin</th></tr></thead>
                            <tbody>
                            <?php foreach ($quotaReqs as $qr): ?>
                                <tr>
                                    <td><?php echo vr_h(vr_fmt_date((string)$qr['created_at'])); ?></td>
                                    <td>VET: <?php echo (int)$qr['requested_max_vets']; ?>, SEG: <?php echo (int)$qr['requested_max_secretaries']; ?></td>
                                    <td><?php echo vr_h((string)$qr['status']); ?></td>
                                    <td><?php echo vr_h((string)($qr['admin_note'] ?? '')); ?></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>
                </div>

                <?php endif; // teamLocked ?>

            </div>
        </div>
        <?php
        vr_layout_end();
        break;
    }

    /* SCAN CODE (mobile): CHIEF scans a membership QR/link and is redirected to activation */
    case 'scan_code': {
        if (!vr_policy_is_admin($user)) {
            vr_layout_start('Scansiona - ' . VETROOM_APP_NAME, $user, $page);
            echo '<div class="vr-card"><p style="font-size:13px;margin:0;">Pagina non disponibile per il tuo ruolo.</p></div>';
            vr_layout_end();
            break;
        }

        vr_layout_start('Scansiona - ' . VETROOM_APP_NAME, $user, $page);
        ?>
        <div class="vr-card" style="padding:14px;">
            <h2 style="margin:0 0 6px;">Scansiona codice affiliazione</h2>
            <p class="vr-muted" style="margin:0 0 12px 0;">
                Inquadra il QR della segretaria. Se il browser lo supporta, la scansione avviene direttamente dalla camera.
            </p>

            <div id="vrScanStatus" class="vr-alert" style="display:none;"></div>

            <div style="display:flex;gap:12px;flex-wrap:wrap;align-items:flex-start;">
                <div style="flex:1;min-width:260px;">
                    <div style="border:1px solid #e5e7eb;border-radius:12px;overflow:hidden;background:#0b1220;">
                        <video id="vrQrVideo" playsinline autoplay muted style="width:100%;height:auto;display:block;"></video>
                    </div>
                    <div style="margin-top:10px;display:flex;gap:8px;flex-wrap:wrap;">
                        <button type="button" class="vr-btn vr-btn-primary" id="vrScanStart">Avvia camera</button>
                        <button type="button" class="vr-btn vr-btn-secondary" id="vrScanStop" disabled>Ferma</button>
                    </div>
                    <p class="vr-muted" style="margin:10px 0 0 0;font-size:12px;">Nota: se la camera non è disponibile, usa il campo sotto e incolla il link.</p>
                </div>

                <div style="width:320px;max-width:100%;">
                    <div class="vr-panel" style="margin:0;">
                        <div style="font-weight:800;margin-bottom:6px;">Incolla link (fallback)</div>
                        <input id="vrScanManual" class="vr-input" placeholder="https://.../app/membership.php?token=..." style="width:100%;">
                        <button type="button" class="vr-btn" style="margin-top:10px;" id="vrScanGo">Apri link</button>
                    </div>
                </div>
            </div>
        </div>

        <script>
        (function(){
            const video = document.getElementById('vrQrVideo');
            const btnStart = document.getElementById('vrScanStart');
            const btnStop = document.getElementById('vrScanStop');
            const status = document.getElementById('vrScanStatus');
            const manual = document.getElementById('vrScanManual');
            const btnGo = document.getElementById('vrScanGo');
            let stream = null;
            let rafId = null;

            function show(msg, type){
                status.style.display = 'block';
                status.className = 'vr-alert ' + (type==='error' ? 'vr-alert-error' : 'vr-alert-success');
                status.textContent = msg;
            }

            function stop(){
                if (rafId) cancelAnimationFrame(rafId);
                rafId = null;
                if (stream) {
                    stream.getTracks().forEach(t => { try{t.stop()}catch(e){} });
                }
                stream = null;
                btnStop.disabled = true;
                btnStart.disabled = false;
            }

            function redirect(data){
                if (!data) return;
                // Accept direct membership links or raw tokens.
                if (data.includes('membership.php')) {
                    window.location.href = data;
                    return;
                }
                // If a token is scanned, build the membership url.
                const tokenMatch = data.match(/token=([A-Za-z0-9]+)/);
                const token = tokenMatch ? tokenMatch[1] : data.trim();
                if (token.length >= 10) {
                    window.location.href = 'membership.php?token=' + encodeURIComponent(token);
                }
            }

            btnGo.addEventListener('click', function(){
                const v = (manual.value||'').trim();
                if (!v) return show('Incolla un link o un token.', 'error');
                redirect(v);
            });

            btnStop.addEventListener('click', stop);

            btnStart.addEventListener('click', async function(){
                status.style.display = 'none';
                if (!('BarcodeDetector' in window)) {
                    show('Scanner QR non supportato da questo browser. Usa il campo "Incolla link".', 'error');
                    return;
                }
                try {
                    const det = new BarcodeDetector({formats:['qr_code']});
                    stream = await navigator.mediaDevices.getUserMedia({video:{facingMode:{ideal:'environment'}}});
                    video.srcObject = stream;
                    btnStart.disabled = true;
                    btnStop.disabled = false;

                    const canvas = document.createElement('canvas');
                    const ctx = canvas.getContext('2d');
                    const tick = async () => {
                        if (!stream) return;
                        try {
                            if (video.readyState >= 2) {
                                canvas.width = video.videoWidth || 640;
                                canvas.height = video.videoHeight || 480;
                                ctx.drawImage(video, 0, 0, canvas.width, canvas.height);
                                const barcodes = await det.detect(canvas);
                                if (barcodes && barcodes.length) {
                                    const raw = barcodes[0].rawValue || '';
                                    stop();
                                    show('QR rilevato. Reindirizzamento...', 'success');
                                    setTimeout(() => redirect(raw), 150);
                                    return;
                                }
                            }
                        } catch (e) {
                            // ignore single frame errors
                        }
                        rafId = requestAnimationFrame(tick);
                    };
                    rafId = requestAnimationFrame(tick);
                } catch (e) {
                    stop();
                    show('Impossibile avviare la camera. Controlla i permessi del browser.', 'error');
                }
            });
        })();
        </script>
        <?php
        vr_layout_end();
        break;
    }

    case 'pet_detail': {
        $pet_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
        if (vr_policy_is_admin($user)) {
            $stmtPet = $db->prepare("
                SELECT p.*, o.name AS owner_name, o.surname AS owner_surname, o.email AS owner_email, o.id AS owner_id
                FROM pets p
                JOIN owners o ON p.owner_id = o.id
                WHERE p.id = ? AND p.clinic_id = ?
            ");
        } else {
            $stmtPet = $db->prepare("
                SELECT p.*, o.name AS owner_name, o.surname AS owner_surname, o.email AS owner_email, o.id AS owner_id
                FROM pets p
                JOIN owners o ON p.owner_id = o.id
                JOIN vet_owner_relations r ON r.owner_id=o.id AND r.clinic_id=o.clinic_id
                WHERE p.id = ? AND p.clinic_id = ? AND r.status='active'
            ");
        }
        $stmtPet->execute([$pet_id, $clinicId]);
        $pet = $stmtPet->fetch(PDO::FETCH_ASSOC);
        if (!$pet) {
            vr_layout_start('Animale non trovato - ' . VETROOM_APP_NAME, $user, 'pets');
            echo '<div class="vr-card"><p style="font-size:13px;margin:0;">Animale non trovato.</p></div>';
            vr_layout_end();
            break;
        }

        $msg_error = '';
        $msg_success = '';

        // Aggiorna dati paziente
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && ( $_POST['form_action'] ?? '' ) === 'update_pet') {
            $breed = trim($_POST['breed'] ?? '');
            $birth_date = trim($_POST['birth_date'] ?? '');
            $sex = trim($_POST['sex'] ?? '');
            $neuter_status = trim($_POST['neuter_status'] ?? '');
            $weight_kg = $_POST['weight_kg'] !== '' ? (float)$_POST['weight_kg'] : null;
            $microchip = trim($_POST['microchip'] ?? '');
            $notes = trim($_POST['notes'] ?? '');

            try {
                $now = vr_now_iso();
                $stmtUp = $db->prepare("
                    UPDATE pets
                       SET breed = ?, birth_date = ?, sex = ?, neuter_status = ?, weight_kg = ?, microchip = ?, notes = ?, updated_at = ?
                     WHERE id = ? AND clinic_id = ?
                ");
                $stmtUp->execute([$breed, $birth_date, $sex, $neuter_status, $weight_kg, $microchip, $notes, $now, $pet_id, $clinicId]);
                $msg_success = 'Dati paziente aggiornati.';
                $stmtPet->execute([$pet_id, $clinicId]);
                $pet = $stmtPet->fetch(PDO::FETCH_ASSOC);
            } catch (Exception $e) {
                $msg_error = 'Errore durante l\'aggiornamento: ' . $e->getMessage();
            }
        }

        // Upload documento
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && ( $_POST['form_action'] ?? '' ) === 'upload_document') {
            if (!isset($_FILES['document']) || $_FILES['document']['error'] !== UPLOAD_ERR_OK) {
                $msg_error = 'Errore nel caricamento del file.';
            } else {
                $file = $_FILES['document'];
                if (($file['size'] ?? 0) > 10 * 1024 * 1024) {
                    $msg_error = 'File troppo grande (max 10MB).';
                } else {
                    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
                    $allowedExt = ['pdf','jpg','jpeg','png','gif','webp'];
                    if ($ext === '' || !in_array($ext, $allowedExt, true)) {
                        $msg_error = 'Formato non supportato (pdf/jpg/png/gif/webp).';
                    } else {
                        // Multi-tenant safe path (separate folders per clinic).
                        $privateDir = __DIR__ . '/data/private_uploads/clinic_' . $clinicId . '/docs';
                        if (!is_dir($privateDir)) {
                            @mkdir($privateDir, 0775, true);
                        }
                        $safeName = 'doc_' . $pet_id . '_' . time() . '_' . mt_rand(1000, 9999) . '.' . preg_replace('/[^a-z0-9]/i', '', $ext);
                        $destPath = $privateDir . '/' . $safeName;
                        if (!move_uploaded_file($file['tmp_name'], $destPath)) {
                            $msg_error = 'Impossibile salvare il file sul server.';
                        } else {
                            $mime = '';
                            if (class_exists('finfo')) {
                                try {
                                    $fi = new finfo(FILEINFO_MIME_TYPE);
                                    $mime = (string)$fi->file($destPath);
                                } catch (Throwable $t) {
                                    $mime = '';
                                }
                            }
                            if ($mime === '') {
                                $mime = (string)($file['type'] ?? '');
                            }
                            $now = vr_now_iso();
                            $stmtDoc = $db->prepare("
                                INSERT INTO documents (clinic_id, pet_id, owner_id, visit_id, filename, original_name, mime_type, doc_role, created_at)
                                VALUES (?, ?, ?, NULL, ?, ?, ?, ?, ?)
                            ");
                            $stmtDoc->execute([
                                $clinicId,
                                $pet_id,
                                (int)$pet['owner_id'],
                                'data/private_uploads/clinic_' . $clinicId . '/docs/' . $safeName,
                                $file['name'],
                                $mime,
                                'GENERIC',
                                $now
                            ]);
                            $msg_success = 'Documento caricato correttamente.';
                        }
                    }
                }
            }
        }

        // Eliminazione documento (Cartella clinica)
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && ( $_POST['form_action'] ?? '' ) === 'delete_document') {
            if ((string)($user['role'] ?? '') === 'READONLY') {
                $msg_error = 'Permesso insufficiente.';
            } else {
            $doc_id = isset($_POST['doc_id']) ? (int)$_POST['doc_id'] : 0;
            if ($doc_id > 0) {
                try {
                    $st = $db->prepare("SELECT id, filename, doc_role, visit_id FROM documents WHERE id=? AND clinic_id=? AND pet_id=? LIMIT 1");
                    $st->execute([$doc_id, $clinicId, $pet_id]);
                    $doc = $st->fetch(PDO::FETCH_ASSOC);
                    if (!$doc) {
                        $msg_error = 'Documento non trovato.';
                    } else {
                        $role = (string)($doc['doc_role'] ?? '');
                        if (vr_policy_role($user) === 'SECRETARY') {
                            // Secretaries must not touch visit-related documents.
                            $vid = (int)($doc['visit_id'] ?? 0);
                            if ($vid > 0 || stripos($role, 'VISIT_PDF') === 0 || $role === 'ATTACHMENT') {
                                throw new Exception('Permesso insufficiente.');
                            }
                        }
                        if ($role === 'VISIT_PDF_FINAL') {
                            throw new Exception('Il PDF definitivo non può essere eliminato.');
                        }
                        if ($role === 'ATTACHMENT') {
                            throw new Exception('Questo allegato va gestito dalla pagina della visita.');
                        }
                        $fn = (string)($doc['filename'] ?? '');
                        if ($fn !== '') {
                            // Best-effort safe delete (only inside app/data)
                            vr_safe_unlink_rel($fn);
                        }
                        $db->prepare("DELETE FROM documents WHERE id=? AND clinic_id=?")
                           ->execute([$doc_id, $clinicId]);
                        $msg_success = 'Documento eliminato correttamente.';
                    }
                } catch (Throwable $t) {
                    $msg_error = 'Errore nell\'eliminazione: ' . $t->getMessage();
                }
            }
            }
        }

        // Visite
        
        // Cancellazione visita (da storico)
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && ( $_POST['form_action'] ?? '' ) === 'delete_visit') {
            $del_id = isset($_POST['visit_id']) ? (int)$_POST['visit_id'] : 0;
            if ($del_id > 0) {
                try {
                    if ((string)($user['role'] ?? '') === 'READONLY') {
                        throw new Exception('Permesso insufficiente.');
                    }
                    // Medico-legal constraint: do not allow deleting a visit that has a definitive PDF.
                    $stFinal = $db->prepare("SELECT id FROM documents WHERE clinic_id=? AND visit_id=? AND doc_role='VISIT_PDF_FINAL' LIMIT 1");
                    $stFinal->execute([$clinicId, $del_id]);
                    if ($stFinal->fetchColumn()) {
                        throw new Exception('Impossibile eliminare una visita che ha già un PDF definitivo.');
                    }

                    // Cleanup draft PDFs + attachments for this visit (best effort).
                    $stDocs = $db->prepare("SELECT id, filename FROM documents WHERE clinic_id=? AND visit_id=?");
                    $stDocs->execute([$clinicId, $del_id]);
                    $rows = $stDocs->fetchAll(PDO::FETCH_ASSOC);
                    foreach ($rows as $r) {
                        $fn = (string)($r['filename'] ?? '');
                        if ($fn !== '') vr_safe_unlink_rel($fn);
                        $db->prepare("DELETE FROM documents WHERE id=? AND clinic_id=?")->execute([(int)$r['id'], $clinicId]);
                    }
                    $stmtDel = $db->prepare("DELETE FROM visits WHERE id = ? AND clinic_id = ? AND pet_id = ?");
                    $stmtDel->execute([$del_id, $clinicId, $pet_id]);
                    $msg_success = 'Visita eliminata correttamente.';
                } catch (Exception $e) {
                    $msg_error = 'Errore nell\'eliminazione: ' . vr_h($e->getMessage());
                }
            }
        }
        $stmtVisits = $db->prepare("
            SELECT v.*, vf.name AS visit_form_name
            FROM visits v
            LEFT JOIN visit_forms vf ON vf.id = v.visit_form_id AND vf.clinic_id = v.clinic_id
            WHERE v.pet_id = ? AND v.clinic_id = ?
            ORDER BY v.visit_date DESC, v.id DESC
        ");
        $stmtVisits->execute([$pet_id, $clinicId]);
        $visits = $stmtVisits->fetchAll(PDO::FETCH_ASSOC);

        // Custom visit forms (for the quick "Nuova visita" selector)
        $stmtVF = $db->prepare("SELECT id, name FROM visit_forms WHERE clinic_id = ? ORDER BY name ASC");
        $stmtVF->execute([$clinicId]);
        $customVisitForms = $stmtVF->fetchAll(PDO::FETCH_ASSOC);

        // Documenti
        //  - Schede visita (PDF generati): mostrati separatamente
        //  - Documenti cartella clinica: upload manuale
        //  - Allegati visita (ATTACHMENT): non vanno nella cartella clinica come singoli documenti

        $stmtVisitDocs = $db->prepare(
            "SELECT d.*, v.visit_date, v.title AS visit_title, v.visit_kind, vf.name AS visit_form_name
             FROM documents d
             LEFT JOIN visits v ON v.id = d.visit_id AND v.clinic_id = d.clinic_id
             LEFT JOIN visit_forms vf ON vf.id = v.visit_form_id AND vf.clinic_id = v.clinic_id
             WHERE d.pet_id = ? AND d.clinic_id = ?
               AND d.mime_type = 'application/pdf'
               AND (d.doc_role LIKE 'VISIT_PDF%')
             ORDER BY d.created_at DESC"
        );
        $stmtVisitDocs->execute([$pet_id, $clinicId]);
        $visitDocs = $stmtVisitDocs->fetchAll(PDO::FETCH_ASSOC);

        $stmtDocs = $db->prepare(
            "SELECT *
             FROM documents
             WHERE pet_id = ? AND clinic_id = ?
               AND (doc_role IS NULL OR doc_role = '' OR doc_role = 'GENERIC')
             ORDER BY created_at DESC"
        );
        $stmtDocs->execute([$pet_id, $clinicId]);
        $docs = $stmtDocs->fetchAll(PDO::FETCH_ASSOC);

        vr_layout_start('Scheda animale - ' . VETROOM_APP_NAME, $user, 'pets');
        ?>
        <h1 class="vr-page-title">Scheda animale</h1>
        <p class="vr-page-subtitle">Dati paziente, visite e cartella clinica.</p>

        <div class="vr-card">
            <div class="vr-card-header">Dati paziente</div>
            <?php if ($msg_error): ?>
                <div class="vr-alert vr-alert-error"><?php echo vr_h($msg_error); ?></div>
            <?php endif; ?>
            <?php if ($msg_success): ?>
                <div class="vr-alert vr-alert-success"><?php echo vr_h($msg_success); ?></div>
            <?php endif; ?>

            <form method="post" enctype="multipart/form-data">
                <input type="hidden" name="form_action" value="update_pet">
                <?php echo vr_staff_csrf_field(); ?>
                <p style="font-size:13px;margin:0 0 6px;">
                    <strong><?php echo vr_h($pet['name']); ?></strong> – <?php echo vr_h($pet['species']); ?><br>
                    Proprietario: <?php echo vr_h($pet['owner_surname'] . ' ' . $pet['owner_name']); ?>
                </p>
                <div class="vr-form-row">
                    <label class="vr-label" for="breed">Razza</label>
                    <input class="vr-input" type="text" id="breed" name="breed" value="<?php echo vr_h($pet['breed']); ?>">
                </div>
                <div class="vr-form-row">
                    <label class="vr-label" for="birth_date">Data di nascita</label>
                    <input class="vr-input" type="date" id="birth_date" name="birth_date" value="<?php echo vr_h($pet['birth_date']); ?>">
                </div>
                <div class="vr-form-row">
                    <label class="vr-label" for="sex">Sesso</label>
                    <select class="vr-select" id="sex" name="sex">
                        <option value="" <?php echo $pet['sex'] === '' ? 'selected' : ''; ?>>N/D</option>
                        <option value="M" <?php echo $pet['sex'] === 'M' ? 'selected' : ''; ?>>Maschio</option>
                        <option value="F" <?php echo $pet['sex'] === 'F' ? 'selected' : ''; ?>>Femmina</option>
                    </select>
                </div>
                <div class="vr-form-row">
                    <label class="vr-label" for="neuter_status">Stato riproduttivo</label>
                    <select class="vr-select" id="neuter_status" name="neuter_status">
                        <option value="" <?php echo $pet['neuter_status'] === '' ? 'selected' : ''; ?>>N/D</option>
                        <option value="INTERO" <?php echo $pet['neuter_status'] === 'INTERO' ? 'selected' : ''; ?>>Intero</option>
                        <option value="STERILIZZATO" <?php echo $pet['neuter_status'] === 'STERILIZZATO' ? 'selected' : ''; ?>>Castrato/Sterilizzato</option>
                    </select>
                </div>
                <div class="vr-form-row">
                    <label class="vr-label" for="weight_kg">Peso (kg)</label>
                    <input class="vr-input" type="number" step="0.1" id="weight_kg" name="weight_kg" value="<?php echo vr_h($pet['weight_kg']); ?>">
                </div>
                <div class="vr-form-row">
                    <label class="vr-label" for="microchip">Microchip</label>
                    <input class="vr-input" type="text" id="microchip" name="microchip" value="<?php echo vr_h($pet['microchip']); ?>">
                </div>
                <div class="vr-form-row">
                    <label class="vr-label" for="notes">Note cliniche</label>
                    <textarea class="vr-textarea" id="notes" name="notes"><?php echo vr_h($pet['notes']); ?></textarea>
                </div>
                <button type="submit" class="vr-button">Aggiorna dati paziente</button>
                <a href="index.php?page=owner_detail&id=<?php echo (int)$pet['owner_id']; ?>" class="vr-button vr-button-secondary" style="margin-left:6px;font-size:11px;padding:5px 10px;">Scheda proprietario</a>

                <?php if (vr_policy_role($user) !== 'SECRETARY'): ?>
                    <a href="index.php?page=visit_new&pet_id=<?php echo (int)$pet_id; ?>&kind=clinical" class="vr-button" style="margin-left:6px;font-size:11px;padding:5px 10px;">Nuova visita clinica</a>
                    <a href="index.php?page=visit_new&pet_id=<?php echo (int)$pet_id; ?>&kind=oftalmo" class="vr-button" style="margin-left:6px;font-size:11px;padding:5px 10px;">Nuova visita oculistica</a>
                    <?php if (!empty($customVisitForms)): ?>
                        <span style="display:inline-flex;align-items:center;gap:6px;margin-left:6px;">
                            <select class="vr-select" id="vr_custom_visit_form" style="font-size:11px;padding:5px 10px;">
                                <option value="">Visita personalizzata…</option>
                                <?php foreach ($customVisitForms as $cvf): ?>
                                    <option value="<?php echo (int)$cvf['id']; ?>"><?php echo vr_h($cvf['name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                            <button type="button" class="vr-button" style="font-size:11px;padding:5px 10px;" onclick="(function(){var s=document.getElementById('vr_custom_visit_form'); if(!s||!s.value){alert('Seleziona un modulo visita.'); return;} window.location.href='index.php?page=visit_new&pet_id=<?php echo (int)$pet_id; ?>&kind=custom&form_id='+encodeURIComponent(s.value);})();">Crea</button>
                        </span>
                    <?php endif; ?>
                <?php else: ?>
                    <span style="margin-left:6px;color:#666;font-size:12px;">Le visite sono gestibili solo dai veterinari.</span>
                <?php endif; ?>
            </form>
        </div>

        <div class="vr-card">
            <div class="vr-card-header">Storico visite</div>
            <?php if (vr_policy_role($user) === 'SECRETARY'): ?>
                <p style="font-size:13px;margin:0;">Le visite cliniche sono accessibili solo ai veterinari.</p>
            <?php elseif (!$visits): ?>
                <p style="font-size:13px;margin:0;">Nessuna visita registrata per questo animale.</p>
            <?php else: ?>
                <table class="vr-table">
                    <thead>
                    <tr>
                        <th>Data</th>
                        <th>Tipo</th>
                        <th>Diagnosi</th>
                        <th>Azioni</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($visits as $v): ?>
                        <tr>
                            <td><?php echo vr_h($v['visit_date']); ?></td>
                            <td><?php
                                if ($v['visit_kind'] === 'OFTALMO') {
                                    echo 'Oftalmologica';
                                } elseif ($v['visit_kind'] === 'CUSTOM') {
                                    echo vr_h($v['visit_form_name'] ?: 'Visita personalizzata');
                                } else {
                                    echo 'Clinica';
                                }
                            ?></td>
                            <td><?php echo vr_h($v['diagnosis']); ?></td>
                            <td>
                                <a href="index.php?page=visit_show&id=<?php echo (int)$v['id']; ?>" class="vr-button" style="font-size:11px;padding:3px 8px;">Apri scheda</a>
                            
                                <a href="index.php?page=visit_edit&id=<?php echo (int)$v['id']; ?>" class="vr-button vr-button-secondary" style="font-size:11px;padding:3px 8px;margin-left:4px;">Modifica</a>
                                <form method="post" action="index.php?page=pet_detail&id=<?php echo (int)$pet_id; ?>" style="display:inline;margin-left:4px;" onsubmit="return confirm('Confermi l\'eliminazione di questa visita?');">
                                    <input type="hidden" name="form_action" value="delete_visit">
                                    <?php echo vr_staff_csrf_field(); ?>
                                    <input type="hidden" name="visit_id" value="<?php echo (int)$v['id']; ?>">
                                    <button type="submit" class="vr-button vr-button-danger vr-icon-submit"><img class="vr-action-icon vr-btn-ico" src="public/icons/delete_visit.png" alt="Elimina"><span class="vr-btn-label">Elimina</span></button>
                                </form>
    </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>

        <div class="vr-card">
            <div class="vr-card-header">Schede visita (PDF)</div>
            <?php if (vr_policy_role($user) === 'SECRETARY'): ?>
                <p style="font-size:13px;margin:0;">I PDF delle schede visita sono accessibili solo ai veterinari.</p>
            <?php elseif (empty($visitDocs)): ?>
                <p style="font-size:13px;margin:0;">Nessun PDF scheda visita generato per questo animale.</p>
            <?php else: ?>
                <table class="vr-table">
                    <thead>
                    <tr>
                        <th>Data visita</th>
                        <th>Titolo</th>
                        <th>Stato</th>
                        <th>Data generazione</th>
                        <th>Link</th>
                        <th>Azioni</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($visitDocs as $d): ?>
                        <?php
                            $role = (string)($d['doc_role'] ?? '');
                            $isFinal = ($role === 'VISIT_PDF_FINAL');
                            $kind = (string)($d['visit_kind'] ?? '');
                            $title = trim((string)($d['visit_title'] ?? ''));
                            if ($title === '') {
                                if ($kind === 'OFTALMO') $title = 'Visita oftalmologica';
                                elseif ($kind === 'CUSTOM') $title = (string)($d['visit_form_name'] ?? 'Visita personalizzata');
                                else $title = 'Visita clinica';
                            }
                        ?>
                        <tr>
                            <td><?php echo vr_h((string)($d['visit_date'] ?? '')); ?></td>
                            <td><?php echo vr_h($title); ?></td>
                            <td><?php echo $isFinal ? '<strong>Definitivo</strong>' : 'Bozza'; ?></td>
                            <td><?php echo vr_h((string)($d['created_at'] ?? '')); ?></td>
                            <td><a href="download.php?doc_id=<?php echo (int)$d['id']; ?>&inline=1" target="_blank" rel="noopener">Apri</a></td>
                            <td>
                                <?php if (!$isFinal && ((string)($user['role'] ?? '') !== 'READONLY')): ?>
                                    <form method="post" style="display:inline;margin:0;" onsubmit="return confirm('Eliminare questa bozza PDF?');">
                                        <input type="hidden" name="form_action" value="delete_document">
                                        <?php echo vr_staff_csrf_field(); ?>
                                        <input type="hidden" name="doc_id" value="<?php echo (int)$d['id']; ?>">
                                        <button type="submit" class="vr-button vr-button-danger vr-icon-submit"><img class="vr-action-icon vr-btn-ico" src="public/icons/delete_document.png" alt="Elimina"><span class="vr-btn-label">Elimina</span></button>
                                    </form>
                                <?php else: ?>
                                    <span style="opacity:.6;">—</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                <p style="font-size:12px;color:#666;margin:8px 0 0;">Nota: il PDF <strong>definitivo</strong> non è eliminabile.</p>
            <?php endif; ?>
        </div>

        <div class="vr-card">
            <div class="vr-card-header">Cartella clinica – documenti</div>
            <form method="post" enctype="multipart/form-data" style="margin-bottom:10px;">
                <input type="hidden" name="form_action" value="upload_document">
                <?php echo vr_staff_csrf_field(); ?>
                <div class="vr-form-row">
                    <label class="vr-label" for="document">Carica documento (PDF, immagine...)</label>
                    <input class="vr-input" type="file" id="document" name="document" required>
                </div>
                <button type="submit" class="vr-button vr-icon-submit"><img class="vr-action-icon vr-btn-ico" src="public/icons/upload_document.png" alt="Carica documento"><span class="vr-btn-label">Carica documento</span></button>
            </form>

            <?php if (empty($docs)): ?>
                <p style="font-size:13px;margin:0;">Nessun documento caricato.</p>
            <?php else: ?>
                <table class="vr-table">
                    <thead>
                    <tr>
                        <th>Documento</th>
                        <th>Data upload</th>
                        <th>Link</th>
                        <th>Azioni</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($docs as $d): ?>
                        <tr>
                            <td><?php echo vr_h((string)($d['original_name'] ?? '')); ?></td>
                            <td><?php echo vr_h((string)($d['created_at'] ?? '')); ?></td>
                            <td><a href="download.php?doc_id=<?php echo (int)$d['id']; ?>&inline=1" target="_blank" rel="noopener">Apri</a></td>
                            <td>
                                <?php
                                    $mt = strtolower((string)($d['mime_type'] ?? ''));
                                    $fn = strtolower((string)($d['filename'] ?? ''));
                                    $isPdf = (strpos($mt, 'pdf') !== false) || (substr($fn, -4) === '.pdf');
                                ?>
                                <?php if ($isPdf && ((string)($user['role'] ?? '') !== 'READONLY')): ?>
                                    <form method="post" style="margin:0;display:inline" onsubmit="return confirm('Eliminare questo PDF?');">
                                        <input type="hidden" name="form_action" value="delete_document">
                                        <?php echo vr_staff_csrf_field(); ?>
                                        <input type="hidden" name="doc_id" value="<?php echo (int)$d['id']; ?>">
                                        <button type="submit" class="vr-button vr-button-danger vr-icon-submit"><img class="vr-action-icon vr-btn-ico" src="public/icons/delete_document.png" alt="Elimina"><span class="vr-btn-label">Elimina</span></button>
                                    </form>
                                <?php else: ?>
                                    <span style="opacity:.6;">—</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
        <?php
        vr_layout_end();
        break;
    }

    /* NUOVA VISITA (clinica / oftalmo) */
    case 'visit_new': {
        // Segreteria (e altri ruoli non veterinari) non possono creare/compilare visite.
        if (!vr_policy_can('visit_write', $user)) {
            vr_layout_start('Operazione non autorizzata - ' . VETROOM_APP_NAME, $user, 'pets');
            echo '<div class="vr-card"><p style="font-size:13px;margin:0;">Le visite cliniche sono gestibili solo dai veterinari.</p></div>';
            vr_layout_end();
            break;
        }

        $pet_id = isset($_GET['pet_id']) ? (int)$_GET['pet_id'] : 0;
        $kindSlug = strtolower(trim((string)($_GET['kind'] ?? 'clinical')));
        $customFormId = (int)($_GET['form_id'] ?? 0);
        $customForm = null;
        $customDef = null;

        if ($kindSlug === 'custom') {
            $visit_kind = 'CUSTOM';
        } else {
            $visit_kind = ($kindSlug === 'oftalmo') ? 'OFTALMO' : 'CLINICA';
        }

        if (vr_policy_is_admin($user)) {
            $stmtPet = $db->prepare("
                SELECT p.*, o.name AS owner_name, o.surname AS owner_surname, o.email AS owner_email, o.id AS owner_id
                FROM pets p
                JOIN owners o ON p.owner_id = o.id
                WHERE p.id = ? AND p.clinic_id = ?
            ");
        } else {
            $stmtPet = $db->prepare("
                SELECT p.*, o.name AS owner_name, o.surname AS owner_surname, o.email AS owner_email, o.id AS owner_id
                FROM pets p
                JOIN owners o ON p.owner_id = o.id
                JOIN vet_owner_relations r ON r.owner_id=o.id AND r.clinic_id=o.clinic_id
                WHERE p.id = ? AND p.clinic_id = ? AND r.status='active'
            ");
        }
        $stmtPet->execute([$pet_id, $clinicId]);
        $pet = $stmtPet->fetch(PDO::FETCH_ASSOC);
        if (!$pet) {
            vr_layout_start('Animale non trovato - ' . VETROOM_APP_NAME, $user, 'pets');
            echo '<div class="vr-card"><p style="font-size:13px;margin:0;">Animale non trovato.</p></div>';
            vr_layout_end();
            break;
        }

        // Load custom visit form definition (if needed)
        if ($visit_kind === 'CUSTOM') {
            if ($customFormId <= 0) {
                vr_layout_start('Modulo visita mancante - ' . VETROOM_APP_NAME, $user, 'pets');
                echo '<div class="vr-card"><p style="font-size:13px;margin:0;">Seleziona un modulo visita personalizzato prima di creare una visita.</p>';
                echo '<p style="margin:10px 0 0;"><a class="vr-button" href="index.php?page=pet_detail&id=' . (int)$pet_id . '">Torna alla scheda paziente</a></p></div>';
                vr_layout_end();
                break;
            }

            $stmtVF = $db->prepare("SELECT * FROM visit_forms WHERE id = ? AND clinic_id = ?");
            $stmtVF->execute([$customFormId, $clinicId]);
            $customForm = $stmtVF->fetch(PDO::FETCH_ASSOC) ?: null;
            if (!$customForm) {
                vr_layout_start('Modulo visita non trovato - ' . VETROOM_APP_NAME, $user, 'pets');
                echo '<div class="vr-card"><p style="font-size:13px;margin:0;">Modulo visita non trovato o non accessibile.</p>';
                echo '<p style="margin:10px 0 0;"><a class="vr-button" href="index.php?page=pet_detail&id=' . (int)$pet_id . '">Torna alla scheda paziente</a></p></div>';
                vr_layout_end();
                break;
            }
            $customDef = null;
            if (!empty($customForm['definition_json'])) {
                $tmp = json_decode((string)$customForm['definition_json'], true);
                if (is_array($tmp)) $customDef = $tmp;
            }
        }

        $visit_error = '';

        if ($_SERVER['REQUEST_METHOD'] === 'POST' && ( $_POST['form_action'] ?? '' ) === 'create_visit') {
            $visit_date = trim($_POST['visit_date'] ?? vr_today_date());
            $title = trim($_POST['title'] ?? '');
            $diagnosis = trim($_POST['diagnosis'] ?? '');
            $therapy = trim($_POST['therapy'] ?? '');
            $notes = trim($_POST['notes'] ?? '');
            $send_email = !empty($_POST['send_email']);

            if ($visit_date === '') {
                $visit_error = 'La data della visita è obbligatoria.';
            } else {
                // raccogli i campi specifici
                if ($visit_kind === 'CLINICA') {
                    $formData = [
                        'regime' => trim($_POST['regime'] ?? ''),
                        'reason' => trim($_POST['reason'] ?? ''),
                        'anamnesis' => trim($_POST['anamnesis'] ?? ''),
                        'objective' => [
                            'Feci' => trim($_POST['obj_feci'] ?? ''),
                            'Temperatura' => trim($_POST['obj_temperatura'] ?? ''),
                            'Frequenza cardiaca' => trim($_POST['obj_fc'] ?? ''),
                            'Stato sensorio' => trim($_POST['obj_sensorio'] ?? ''),
                            'Respirazione' => trim($_POST['obj_respirazione'] ?? ''),
                            'Mucose' => trim($_POST['obj_mucose'] ?? ''),
                            'Riempimento capillare' => trim($_POST['obj_riempimento_capillare'] ?? ''),
                            'Stato di idratazione' => trim($_POST['obj_idratazione'] ?? ''),
                            'Note linfonodi' => trim($_POST['obj_linfonodi'] ?? ''),
                            'Polso femorale' => trim($_POST['obj_polso_femorale'] ?? ''),
                            'Polso tarsale' => trim($_POST['obj_polso_tarsale'] ?? ''),
                            'Auscultazione cardiaca' => trim($_POST['obj_auscultazione_cardiaca'] ?? ''),
                            'Apparato respiratorio' => trim($_POST['obj_apparato_respiratorio'] ?? ''),
                            'Palpazione addome' => trim($_POST['obj_palpazione_addome'] ?? ''),
                        ],
                    ];
                } elseif ($visit_kind === 'CUSTOM') {
                    $fields = [];
                    $defFields = $customDef['fields'] ?? [];
                    if (!is_array($defFields)) $defFields = [];
                    $posted = $_POST['cf'] ?? [];
                    if (!is_array($posted)) $posted = [];
                    foreach ($defFields as $f) {
                        if (!is_array($f)) continue;
                        $key = trim((string)($f['key'] ?? ''));
                        $label = trim((string)($f['label'] ?? ''));
                        if ($key === '' && $label !== '') $key = vr_slugify_key($label);
                        if ($key === '') continue;
                        $type = strtolower(trim((string)($f['type'] ?? 'text')));
                        $section = trim((string)($f['section'] ?? ''));
                        $val = '';
                        if ($type === 'checkbox') {
                            $val = !empty($posted[$key]) ? 'Sì' : '';
                        } else {
                            $val = trim((string)($posted[$key] ?? ''));
                        }
                        $fields[] = [
                            'key' => $key,
                            'label' => ($label !== '' ? $label : $key),
                            'section' => $section,
                            'type' => $type,
                            'value' => $val
                        ];
                    }
                    $formData = [
                        '_custom_form' => [
                            'id' => $customFormId,
                            'name' => (string)($customForm['name'] ?? ''),
                            'fields' => $fields
                        ]
                    ];

                    // Default title to module name
                    if ($title === '') $title = trim((string)($customForm['name'] ?? ''));
                } else { // OFTALMOLOGICA
                    $formData = [
                        'anamnesis' => trim($_POST['anamnesis'] ?? ''),
                        'reflex' => [
                            'minaccia_dx' => trim($_POST['minaccia_dx'] ?? ''),
                            'minaccia_sx' => trim($_POST['minaccia_sx'] ?? ''),
                            'palpebrale_dx' => trim($_POST['palpebrale_dx'] ?? ''),
                            'palpebrale_sx' => trim($_POST['palpebrale_sx'] ?? ''),
                            'pupillare_dx' => trim($_POST['pupillare_dx'] ?? ''),
                            'pupillare_sx' => trim($_POST['pupillare_sx'] ?? ''),
                            'dazzle_dx' => trim($_POST['dazzle_dx'] ?? ''),
                            'dazzle_sx' => trim($_POST['dazzle_sx'] ?? ''),
                            'corneale_dx' => trim($_POST['corneale_dx'] ?? ''),
                            'corneale_sx' => trim($_POST['corneale_sx'] ?? ''),
                        ],
                        'annessi' => [
                            'orbita_dx' => trim($_POST['ann_orbita_dx'] ?? ''),
                            'orbita_sx' => trim($_POST['ann_orbita_sx'] ?? ''),
                            'palpebre_dx' => trim($_POST['ann_palpebre_dx'] ?? ''),
                            'palpebre_sx' => trim($_POST['ann_palpebre_sx'] ?? ''),
                            'terza_dx' => trim($_POST['ann_terza_dx'] ?? ''),
                            'terza_sx' => trim($_POST['ann_terza_sx'] ?? ''),
                            'lacrimale_dx' => trim($_POST['ann_lacrimale_dx'] ?? ''),
                            'lacrimale_sx' => trim($_POST['ann_lacrimale_sx'] ?? ''),
                            'congiuntiva_dx' => trim($_POST['ann_congiuntiva_dx'] ?? ''),
                            'congiuntiva_sx' => trim($_POST['ann_congiuntiva_sx'] ?? ''),
                        ],
                        'occhio' => [
                            'fluor_dx' => trim($_POST['oc_fluor_dx'] ?? ''),
                            'fluor_sx' => trim($_POST['oc_fluor_sx'] ?? ''),
                            'schirmer_dx' => trim($_POST['oc_schirmer_dx'] ?? ''),
                            'schirmer_sx' => trim($_POST['oc_schirmer_sx'] ?? ''),
                            'cornea_dx' => trim($_POST['oc_cornea_dx'] ?? ''),
                            'cornea_sx' => trim($_POST['oc_cornea_sx'] ?? ''),
                            'camera_dx' => trim($_POST['oc_camera_dx'] ?? ''),
                            'camera_sx' => trim($_POST['oc_camera_sx'] ?? ''),
                            'iride_dx' => trim($_POST['oc_iride_dx'] ?? ''),
                            'iride_sx' => trim($_POST['oc_iride_sx'] ?? ''),
                            'cristallino_dx' => trim($_POST['oc_cristallino_dx'] ?? ''),
                            'cristallino_sx' => trim($_POST['oc_cristallino_sx'] ?? ''),
                            'vitreo_dx' => trim($_POST['oc_vitreo_dx'] ?? ''),
                            'vitreo_sx' => trim($_POST['oc_vitreo_sx'] ?? ''),
                            'fondo_dx' => trim($_POST['oc_fondo_dx'] ?? ''),
                            'fondo_sx' => trim($_POST['oc_fondo_sx'] ?? ''),
                            'iop_dx' => trim($_POST['oc_iop_dx'] ?? ''),
                            'iop_sx' => trim($_POST['oc_iop_sx'] ?? ''),
                        ],
                    ];
                }

                try {
                    $now = vr_now_iso();
                    $visitFormId = ($visit_kind === 'CUSTOM') ? $customFormId : null;
                    $stmtIns = $db->prepare("
                        INSERT INTO visits (clinic_id, pet_id, owner_id, visit_date, visit_kind, visit_form_id, title, diagnosis, therapy, notes, form_data, created_at, updated_at)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                    ");
                    $stmtIns->execute([
                        $clinicId,
                        $pet_id,
                        (int)$pet['owner_id'],
                        $visit_date,
                        $visit_kind,
                        $visitFormId,
                        $title,
                        $diagnosis,
                        $therapy,
                        $notes,
                        json_encode($formData, JSON_UNESCAPED_UNICODE),
                        $now,
                        $now
                    ]);
                    $visitId = (int)$db->lastInsertId();


                    // Genera PDF della scheda visita (CLINICA e OFTALMO) e allega ai documenti
                    $headerRow = vr_get_vet_header($clinicId);
                    $visitRowForPdf = [
                        'owner_surname' => $pet['owner_surname'] ?? '',
                        'owner_name'    => $pet['owner_name'] ?? '',
                        'owner_id'      => (int)($pet['owner_id'] ?? 0),
                        'visit_date'    => $visit_date,
                        'visit_kind'    => $visit_kind,
                        'visit_form_id' => ($visit_kind === 'CUSTOM' ? (int)$customFormId : null),
                        'visit_form_name' => ($visit_kind === 'CUSTOM' ? (string)($customForm['name'] ?? '') : ''),
                        'title'        => $title,
                        'diagnosis'     => $diagnosis,
                        'therapy'       => $therapy,
                        'notes'         => $notes
                    ];

                    // Auto-generate a draft PDF (BOZZA_...) on visit creation.
                    $pdfRel = vr_generate_visit_pdf($headerRow, $pet, $visitRowForPdf, $formData, $clinicId, null, [], false);
                    if ($pdfRel) {
                        try {
                            $stmtDoc = $db->prepare("
                                INSERT INTO documents (clinic_id, pet_id, owner_id, visit_id, filename, original_name, mime_type, doc_role, created_at)
                                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
                            ");
                            $nowDoc = vr_now_iso();
                            $stmtDoc->execute([
                                $clinicId,
                                $pet_id,
                                (int)$pet['owner_id'],
                                $visitId,
                                $pdfRel,
                                basename($pdfRel),
                                'application/pdf',
                                'VISIT_PDF_DRAFT',
                                $nowDoc
                            ]);
                        } catch (Exception $e) {
                            // Non bloccare il salvataggio se fallisce l'allegato
                        }
                    }



                    if ($send_email && !empty($pet['owner_email'])) {
                        $header = vr_get_vet_header($clinicId) ?? [];
    if (!is_array($header)) $header = [];
                        $to = $pet['owner_email'];
                        $subject = 'Scheda visita per ' . $pet['name'] . ' del ' . $visit_date;

                        $body = '';
                        if ($header) {
                            $body .= $header['header_name'] . "\n";
                            if (!empty($header['header_title'])) $body .= $header['header_title'] . "\n";
                            if (!empty($header['header_albo'])) $body .= $header['header_albo'] . "\n";
                            if (!empty($header['header_phone'])) $body .= 'Tel: ' . $header['header_phone'] . "\n";
                            $body .= "\n";
                        }

                        $body .= "Animale: " . $pet['name'] . " (" . $pet['species'] . ")\n";
                        $body .= "Proprietario: " . $pet['owner_surname'] . " " . $pet['owner_name'] . "\n";
                        $body .= "Data visita: " . $visit_date . "\n";
                        $typeLabel = 'Clinica';
                        if ($visit_kind === 'OFTALMO') {
                            $typeLabel = 'Oftalmologica';
                        } elseif ($visit_kind === 'CUSTOM') {
                            $typeLabel = 'Personalizzata: ' . (string)($customForm['name'] ?? '');
                        }
                        $body .= "Tipo visita: " . trim($typeLabel) . "\n\n";

                        if ($visit_kind === 'CLINICA') {
                            $body .= "Motivo della visita:\n" . $formData['reason'] . "\n\n";
                            $body .= "Anamnesi:\n" . $formData['anamnesis'] . "\n\n";
                            $body .= "Esame obiettivo:\n" . (function($obj){ $out=''; if(is_array($obj)){ foreach($obj as $k=>$v){ if(trim((string)$v)!==''){ $out .= ' - ' . $k . ': ' . $v . "\n"; } } } return $out; })($formData['objective'] ?? []) . "\n";
                        } elseif ($visit_kind === 'CUSTOM') {
                            $body .= "Dettagli visita:\n";
                            $fields = $formData['_custom_form']['fields'] ?? [];
                            if (is_array($fields)) {
                                foreach ($fields as $f) {
                                    if (!is_array($f)) continue;
                                    $label = trim((string)($f['label'] ?? ''));
                                    $val = trim((string)($f['value'] ?? ''));
                                    $section = trim((string)($f['section'] ?? ''));
                                    if ($val === '') continue;
                                    $prefix = '';
                                    if ($section !== '') $prefix = $section . ' - ';
                                    $body .= " - " . $prefix . ($label !== '' ? $label : (string)($f['key'] ?? '')) . ": " . $val . "\n";
                                }
                            }
                            $body .= "\n";
                        } else {
                            $body .= "Anamnesi:\n" . $formData['anamnesis'] . "\n\n";
                            $body .= "Riflessi:\n";
                            foreach ($formData['reflex'] as $k => $v) {
                                $body .= " - " . $k . ": " . $v . "\n";
                            }
                            $body .= "\nEsami degli annessi:\n";
                            foreach ($formData['annessi'] as $k => $v) {
                                $body .= " - " . $k . ": " . $v . "\n";
                            }
                            $body .= "\nEsami dell'occhio:\n";
                            foreach ($formData['occhio'] as $k => $v) {
                                $body .= " - " . $k . ": " . $v . "\n";
                            }
                            $body .= "\n";
                        }

                        $body .= "Diagnosi:\n" . $diagnosis . "\n\n";
                        $body .= "Terapia:\n" . $therapy . "\n\n";
                        $body .= "Note:\n" . $notes . "\n";

                        $headers = 'From: VetRoom <' . VETROOM_MAIL_FROM . '>' . "\r\n";
                        @mail($to, $subject, $body, $headers);
                    }

                    header('Location: index.php?page=pet_detail&id=' . $pet_id);
                    exit;
                } catch (Exception $e) {
                    $visit_error = 'Errore durante il salvataggio: ' . $e->getMessage();
                }
            }
        }

        if ($visit_kind === 'OFTALMO') {
            $titleVisit = 'Nuova visita oftalmologica';
        } elseif ($visit_kind === 'CUSTOM') {
            $titleVisit = 'Nuova visita: ' . (!empty($customForm['name']) ? (string)$customForm['name'] : 'Visita personalizzata');
        } else {
            $titleVisit = 'Nuova visita clinica';
        }

        vr_layout_start($titleVisit . ' - ' . VETROOM_APP_NAME, $user, 'pets');
        ?>
        <h1 class="vr-page-title"><?php echo vr_h($titleVisit); ?></h1>
        <p class="vr-page-subtitle">Compila la scheda per <?php echo vr_h($pet['name']); ?>.</p>

        <div class="vr-card">
            <?php if ($visit_error): ?>
                <div class="vr-alert vr-alert-error"><?php echo vr_h($visit_error); ?></div>
            <?php endif; ?>

            <p style="font-size:13px;margin:0 0 8px;">
                <strong><?php echo vr_h($pet['name']); ?></strong> – <?php echo vr_h($pet['species']); ?><br>
                Proprietario: <?php echo vr_h($pet['owner_surname'] . ' ' . $pet['owner_name']); ?>
            </p>

            <form method="post">
                <input type="hidden" name="form_action" value="create_visit">
                <?php echo vr_staff_csrf_field(); ?>
                <div class="vr-form-row">
                    <label class="vr-label" for="visit_date">Data visita</label>
                    <input class="vr-input" type="date" id="visit_date" name="visit_date" value="<?php echo vr_h(vr_today_date()); ?>" required>
                </div>

                <?php if ($visit_kind === 'CLINICA'): ?>
                    <div class="vr-form-row">
                        <label class="vr-label" for="regime">Regime</label>
                        <input class="vr-input" type="text" id="regime" name="regime" placeholder="Es. Ordinario, Urgenza...">
                    </div>
                    <div class="vr-form-row">
                        <label class="vr-label" for="reason">Motivo della visita</label>
                        <textarea class="vr-textarea" id="reason" name="reason"></textarea>
                    </div>
                    <div class="vr-form-row">
                        <label class="vr-label" for="anamnesis">Anamnesi</label>
                        <textarea class="vr-textarea" id="anamnesis" name="anamnesis"></textarea>
                    </div>
                    
                    <div class="vr-form-row">
                        <label class="vr-label">Esame obiettivo</label>
                    </div>
                    <div class="vr-form-row" style="display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:6px;">
                        <input class="vr-input" type="text" name="obj_feci" placeholder="Feci">
                        <input class="vr-input" type="text" name="obj_temperatura" placeholder="Temperatura">
                        <input class="vr-input" type="text" name="obj_fc" placeholder="Frequenza cardiaca">
                        <input class="vr-input" type="text" name="obj_sensorio" placeholder="Stato sensorio">
                        <input class="vr-input" type="text" name="obj_respirazione" placeholder="Respirazione">
                        <input class="vr-input" type="text" name="obj_mucose" placeholder="Mucose">
                        <input class="vr-input" type="text" name="obj_riempimento_capillare" placeholder="Riempimento capillare">
                        <input class="vr-input" type="text" name="obj_idratazione" placeholder="Stato di idratazione">
                        <textarea class="vr-textarea" name="obj_linfonodi" placeholder="Note linfonodi"></textarea>
                        <input class="vr-input" type="text" name="obj_polso_femorale" placeholder="Polso femorale">
                        <input class="vr-input" type="text" name="obj_polso_tarsale" placeholder="Polso tarsale">
                        <textarea class="vr-textarea" name="obj_auscultazione_cardiaca" placeholder="Auscultazione cardiaca"></textarea>
                        <textarea class="vr-textarea" name="obj_apparato_respiratorio" placeholder="Apparato respiratorio"></textarea>
                        <textarea class="vr-textarea" name="obj_palpazione_addome" placeholder="Palpazione addome"></textarea>
                    </div>
<?php elseif ($visit_kind === 'CUSTOM'): ?>
                    <div class="vr-form-row">
                        <label class="vr-label" for="title">Titolo visita</label>
                        <input class="vr-input" type="text" id="title" name="title" value="<?php echo vr_h($customForm['name'] ?? ''); ?>" placeholder="Es. Visita ecografica, Visita dermatologica...">
                    </div>

                    <?php
                        $cfFields = is_array($customDef['fields'] ?? null) ? $customDef['fields'] : [];
                        $grouped = [];
                        foreach ($cfFields as $f) {
                            if (!is_array($f)) continue;
                            $sec = trim((string)($f['section'] ?? ''));
                            $grouped[$sec][] = $f;
                        }
                        if (empty($grouped)) $grouped = ['' => []];
                    ?>

                    <?php foreach ($grouped as $sec => $fields): ?>
                        <?php if (trim((string)$sec) !== ''): ?>
                            <div class="vr-form-row"><label class="vr-label"><?php echo vr_h($sec); ?></label></div>
                        <?php endif; ?>

                        <?php foreach ($fields as $f): ?>
                            <?php
                                $key = trim((string)($f['key'] ?? ''));
                                $lbl = trim((string)($f['label'] ?? ''));
                                if ($lbl === '') continue;
                                if ($key === '') $key = vr_slugify_key($lbl);
                                $type = strtolower(trim((string)($f['type'] ?? 'text')));
                                $required = !empty($f['required']);
                                $opts = $f['options'] ?? [];
                                if (is_string($opts)) $opts = array_values(array_filter(array_map('trim', explode(',', $opts))));
                                if (!is_array($opts)) $opts = [];
                            ?>
                            <div class="vr-form-row">
                                <label class="vr-label" for="cf_<?php echo vr_h($key); ?>"><?php echo vr_h($lbl); ?></label>

                                <?php if ($type === 'textarea'): ?>
                                    <textarea class="vr-textarea" id="cf_<?php echo vr_h($key); ?>" name="cf[<?php echo vr_h($key); ?>]" <?php echo $required ? 'required' : ''; ?>></textarea>
                                <?php elseif ($type === 'select'): ?>
                                    <select class="vr-select" id="cf_<?php echo vr_h($key); ?>" name="cf[<?php echo vr_h($key); ?>]" <?php echo $required ? 'required' : ''; ?>>
                                        <option value=""></option>
                                        <?php foreach ($opts as $op): ?>
                                            <option value="<?php echo vr_h($op); ?>"><?php echo vr_h($op); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                <?php elseif ($type === 'checkbox'): ?>
                                    <label style="display:flex;align-items:center;gap:8px;font-size:13px;">
                                        <input type="checkbox" id="cf_<?php echo vr_h($key); ?>" name="cf[<?php echo vr_h($key); ?>]" value="1">
                                        <span>Seleziona</span>
                                    </label>
                                <?php else: ?>
                                    <input class="vr-input" type="<?php echo $type === 'number' ? 'number' : 'text'; ?>" id="cf_<?php echo vr_h($key); ?>" name="cf[<?php echo vr_h($key); ?>]" <?php echo $required ? 'required' : ''; ?>>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    <?php endforeach; ?>

<?php else: ?>
                    <div class="vr-form-row">
                        <label class="vr-label" for="anamnesis">Anamnesi</label>
                        <textarea class="vr-textarea" id="anamnesis" name="anamnesis"></textarea>
                    </div>

                    <div class="vr-form-row">
                        <label class="vr-label">Riflessi</label>
                    </div>
                    <div class="vr-form-row" style="display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:6px;">
                        <input class="vr-input" type="text" name="minaccia_dx" placeholder="Riflesso minaccia DX">
                        <input class="vr-input" type="text" name="minaccia_sx" placeholder="Riflesso minaccia SX">
                        <input class="vr-input" type="text" name="palpebrale_dx" placeholder="Riflesso palpebrale DX">
                        <input class="vr-input" type="text" name="palpebrale_sx" placeholder="Riflesso palpebrale SX">
                        <input class="vr-input" type="text" name="pupillare_dx" placeholder="Riflesso pupillare DX">
                        <input class="vr-input" type="text" name="pupillare_sx" placeholder="Riflesso pupillare SX">
                        <input class="vr-input" type="text" name="dazzle_dx" placeholder="Dazzle DX">
                        <input class="vr-input" type="text" name="dazzle_sx" placeholder="Dazzle SX">
                        <input class="vr-input" type="text" name="corneale_dx" placeholder="Riflesso corneale DX">
                        <input class="vr-input" type="text" name="corneale_sx" placeholder="Riflesso corneale SX">
                    </div>

                    <div class="vr-form-row">
                        <label class="vr-label">Esami degli annessi</label>
                    </div>
                    <div class="vr-form-row" style="display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:6px;">
                        <input class="vr-input" type="text" name="ann_orbita_dx" placeholder="Orbita DX">
                        <input class="vr-input" type="text" name="ann_orbita_sx" placeholder="Orbita SX">
                        <input class="vr-input" type="text" name="ann_palpebre_dx" placeholder="Palpebre DX">
                        <input class="vr-input" type="text" name="ann_palpebre_sx" placeholder="Palpebre SX">
                        <input class="vr-input" type="text" name="ann_terza_dx" placeholder="3a palpebra DX">
                        <input class="vr-input" type="text" name="ann_terza_sx" placeholder="3a palpebra SX">
                        <input class="vr-input" type="text" name="ann_lacrimale_dx" placeholder="Sistema lacrimale DX">
                        <input class="vr-input" type="text" name="ann_lacrimale_sx" placeholder="Sistema lacrimale SX">
                        <input class="vr-input" type="text" name="ann_congiuntiva_dx" placeholder="Congiuntiva DX">
                        <input class="vr-input" type="text" name="ann_congiuntiva_sx" placeholder="Congiuntiva SX">
                    </div>

                    <div class="vr-form-row">
                        <label class="vr-label">Esami Occhio</label>
                    </div>
                    <div class="vr-form-row" style="display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:6px;">
                        <input class="vr-input" type="text" name="oc_fluor_dx" placeholder="Fluoresceina DX">
                        <input class="vr-input" type="text" name="oc_fluor_sx" placeholder="Fluoresceina SX">
                        <input class="vr-input" type="text" name="oc_schirmer_dx" placeholder="Schirmer DX">
                        <input class="vr-input" type="text" name="oc_schirmer_sx" placeholder="Schirmer SX">
                        <input class="vr-input" type="text" name="oc_cornea_dx" placeholder="Cornea DX">
                        <input class="vr-input" type="text" name="oc_cornea_sx" placeholder="Cornea SX">
                        <input class="vr-input" type="text" name="oc_camera_dx" placeholder="Camera anteriore DX">
                        <input class="vr-input" type="text" name="oc_camera_sx" placeholder="Camera anteriore SX">
                        <input class="vr-input" type="text" name="oc_iride_dx" placeholder="Iride e pupilla DX">
                        <input class="vr-input" type="text" name="oc_iride_sx" placeholder="Iride e pupilla SX">
                        <input class="vr-input" type="text" name="oc_cristallino_dx" placeholder="Cristallino DX">
                        <input class="vr-input" type="text" name="oc_cristallino_sx" placeholder="Cristallino SX">
                        <input class="vr-input" type="text" name="oc_vitreo_dx" placeholder="Vitreo DX">
                        <input class="vr-input" type="text" name="oc_vitreo_sx" placeholder="Vitreo SX">
                        <input class="vr-input" type="text" name="oc_fondo_dx" placeholder="Fondo DX">
                        <input class="vr-input" type="text" name="oc_fondo_sx" placeholder="Fondo SX">
                        <input class="vr-input" type="text" name="oc_iop_dx" placeholder="IOP DX (mm Hg)">
                        <input class="vr-input" type="text" name="oc_iop_sx" placeholder="IOP SX (mm Hg)">
                    </div>
                <?php endif; ?>

                <div class="vr-form-row">
                    <label class="vr-label" for="diagnosis">Diagnosi</label>
                    <textarea class="vr-textarea" id="diagnosis" name="diagnosis"></textarea>
                </div>
                <div class="vr-form-row">
                    <label class="vr-label" for="therapy">Terapia</label>
                    <textarea class="vr-textarea" id="therapy" name="therapy"></textarea>
                </div>
                <div class="vr-form-row">
                    <label class="vr-label" for="notes">Note</label>
                    <textarea class="vr-textarea" id="notes" name="notes"></textarea>
                </div>
                <div class="vr-form-row vr-checkbox-row">
                    <input type="checkbox" id="send_email" name="send_email" value="1" <?php echo !empty($pet['owner_email']) ? '' : 'disabled'; ?>>
                    <label for="send_email">Invia questa scheda via email al proprietario</label>
                    <?php if (empty($pet['owner_email'])): ?>
                        <span style="font-size:11px;color:#b91c1c;">(Email proprietario assente in anagrafica)</span>
                    <?php endif; ?>
                </div>
                <button type="submit" class="vr-button vr-icon-submit"><img class="vr-action-icon vr-btn-ico" src="public/icons/add_visit.png" alt="Salva visita"><span class="vr-btn-label">Salva visita</span></button>
                <a href="index.php?page=pet_detail&id=<?php echo (int)$pet_id; ?>" class="vr-button vr-button-secondary" style="margin-left:6px;">Annulla</a>
            </form>
        </div>
        <?php
        vr_layout_end();
        break;
    }

    /* VISITA - VISUALIZZAZIONE */
    

    /* VISITA - MODIFICA (SOLO CAMPI PRINCIPALI) */
    case 'visit_edit': {
        // Segreteria (e altri ruoli non veterinari) non possono modificare visite.
        if (!vr_policy_can('visit_write', $user)) {
            vr_layout_start('Operazione non autorizzata - ' . VETROOM_APP_NAME, $user, 'pets');
            echo '<div class="vr-card"><p style="font-size:13px;margin:0;">Le visite cliniche sono gestibili solo dai veterinari.</p></div>';
            vr_layout_end();
            break;
        }

        $visit_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
        if (vr_policy_is_admin($user)) {
            $stmt = $db->prepare("
                SELECT v.*, vf.name AS visit_form_name, p.name AS pet_name, p.species AS pet_species, o.name AS owner_name, o.surname AS owner_surname, o.id AS owner_id
                FROM visits v
                LEFT JOIN visit_forms vf ON vf.id = v.visit_form_id AND vf.clinic_id = v.clinic_id
                JOIN pets p ON v.pet_id = p.id
                JOIN owners o ON v.owner_id = o.id
                WHERE v.id = ? AND v.clinic_id = ?
            ");
        } else {
            $stmt = $db->prepare("
                SELECT v.*, vf.name AS visit_form_name, p.name AS pet_name, p.species AS pet_species, o.name AS owner_name, o.surname AS owner_surname, o.id AS owner_id
                FROM visits v
                LEFT JOIN visit_forms vf ON vf.id = v.visit_form_id AND vf.clinic_id = v.clinic_id
                JOIN pets p ON v.pet_id = p.id
                JOIN owners o ON v.owner_id = o.id
                JOIN vet_owner_relations r ON r.owner_id=o.id AND r.clinic_id=o.clinic_id
                WHERE v.id = ? AND v.clinic_id = ? AND r.status='active'
            ");
        }
        $stmt->execute([$visit_id, $clinicId]);
        $visit = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$visit) {
            vr_layout_start('Visita non trovata - ' . VETROOM_APP_NAME, $user, 'pets');
            echo '<div class="vr-card"><p style="font-size:13px;margin:0;">Visita non trovata.</p></div>';
            vr_layout_end();
            break;
        }

        $msg_success = '';
        $msg_error = '';

        $formData = json_decode($visit['form_data'] ?? '', true) ?: [];
        $customForm = null;
        $customDef = null;
        $customValues = [];
        if (($visit['visit_kind'] ?? '') === 'CUSTOM') {
            $fid = (int)($visit['visit_form_id'] ?? 0);
            if ($fid > 0) {
                $st = $db->prepare("SELECT * FROM visit_forms WHERE id = ? AND clinic_id = ?");
                $st->execute([$fid, $clinicId]);
                $customForm = $st->fetch(PDO::FETCH_ASSOC) ?: null;
                if ($customForm && !empty($customForm['definition_json'])) {
                    $customDef = json_decode($customForm['definition_json'], true);
                }
            }
            $existingFields = $formData['_custom_form']['fields'] ?? [];
            if (is_array($existingFields)) {
                foreach ($existingFields as $f) {
                    if (!is_array($f)) continue;
                    $k = trim((string)($f['key'] ?? ''));
                    if ($k === '') continue;
                    $customValues[$k] = (string)($f['value'] ?? '');
                }
            }
        }

        if ($_SERVER['REQUEST_METHOD'] === 'POST' && ( $_POST['form_action'] ?? '' ) === 'update_visit') {
            $visit_date = trim($_POST['visit_date'] ?? '');
            $title      = trim($_POST['title'] ?? '');
            $diagnosis  = trim($_POST['diagnosis'] ?? '');
            $therapy    = trim($_POST['therapy'] ?? '');
            $notes      = trim($_POST['notes'] ?? '');
            if ($visit_date === '') {
                $msg_error = 'La data visita è obbligatoria.';
            } else {
                try {
                    $now = vr_now_iso();
                    if (($visit['visit_kind'] ?? '') === 'CUSTOM') {
                        $fid = (int)($visit['visit_form_id'] ?? 0);
                        $defFields = is_array($customDef['fields'] ?? null) ? $customDef['fields'] : [];
                        $cfPosted = $_POST['cf'] ?? [];
                        if (!is_array($cfPosted)) $cfPosted = [];
                        $newFields = [];
                        foreach ($defFields as $f) {
                            if (!is_array($f)) continue;
                            $key = trim((string)($f['key'] ?? ''));
                            $label = trim((string)($f['label'] ?? ''));
                            if ($label === '') continue;
                            if ($key === '') $key = vr_slugify_key($label);
                            $type = strtolower(trim((string)($f['type'] ?? 'text')));
                            $section = trim((string)($f['section'] ?? ''));
                            $value = '';
                            if ($type === 'checkbox') {
                                $value = !empty($cfPosted[$key]) ? 'Sì' : 'No';
                            } else {
                                $value = trim((string)($cfPosted[$key] ?? ''));
                            }
                            $newFields[] = [
                                'key' => $key,
                                'label' => $label,
                                'section' => $section,
                                'type' => $type,
                                'value' => $value,
                            ];
                        }
                        if ($title === '') {
                            $title = (string)($customForm['name'] ?? $visit['title'] ?? '');
                        }
                        $newFormData = [
                            '_custom_form' => [
                                'id' => $fid,
                                'name' => (string)($customForm['name'] ?? ''),
                                'fields' => $newFields,
                            ]
                        ];
                        $formJson = json_encode($newFormData, JSON_UNESCAPED_UNICODE);
                        $stmtUp = $db->prepare("
                            UPDATE visits
                            SET visit_date = ?, title = ?, diagnosis = ?, therapy = ?, notes = ?, form_data = ?, updated_at = ?
                            WHERE id = ? AND clinic_id = ?
                        ");
                        $stmtUp->execute([$visit_date, $title, $diagnosis, $therapy, $notes, $formJson, $now, $visit_id, $clinicId]);
                    } else {
                        $stmtUp = $db->prepare("
                            UPDATE visits
                            SET visit_date = ?, diagnosis = ?, therapy = ?, notes = ?, updated_at = ?
                            WHERE id = ? AND clinic_id = ?
                        ");
                        $stmtUp->execute([$visit_date, $diagnosis, $therapy, $notes, $now, $visit_id, $clinicId]);
                    }
                    $msg_success = 'Visita aggiornata correttamente.';
                    // ricarica la visita aggiornata
                    $stmt->execute([$visit_id, $clinicId]);
                    $visit = $stmt->fetch(PDO::FETCH_ASSOC);
                    $formData = json_decode($visit['form_data'] ?? '', true) ?: [];
                } catch (Exception $e) {
                    $msg_error = 'Errore nell\'aggiornamento: ' . vr_h($e->getMessage());
                }
            }
        }

        vr_layout_start('Modifica visita - ' . VETROOM_APP_NAME, $user, 'visit_edit');
        ?>
        <div class="vr-card">
            <div class="vr-card-header">Modifica visita</div>
            <?php if ($msg_success): ?><div class="vr-alert vr-alert-success"><?php echo $msg_success; ?></div><?php endif; ?>
            <?php if ($msg_error): ?><div class="vr-alert vr-alert-error"><?php echo $msg_error; ?></div><?php endif; ?>
            <p style="font-size:13px;margin:0 0 12px 0;">
                <strong>Animale:</strong> <?php echo vr_h($visit['pet_name'] . ' (' . $visit['pet_species'] . ')'); ?><br>
                <strong>Proprietario:</strong> <?php echo vr_h($visit['owner_surname'] . ' ' . $visit['owner_name']); ?><br>
                <strong>Tipo visita:</strong> <?php
                    if (($visit['visit_kind'] ?? '') === 'OFTALMO') {
                        echo 'Oftalmologica';
                    } elseif (($visit['visit_kind'] ?? '') === 'CUSTOM') {
                        echo vr_h($visit['visit_form_name'] ?: 'Visita personalizzata');
                    } else {
                        echo 'Clinica';
                    }
                ?>
            </p>
            <form method="post">
                <input type="hidden" name="form_action" value="update_visit">
                <?php echo vr_staff_csrf_field(); ?>
                <div class="vr-form-row">
                    <label class="vr-label" for="visit_date">Data visita</label>
                    <input class="vr-input" type="date" id="visit_date" name="visit_date" value="<?php echo vr_h($visit['visit_date']); ?>" required>
                </div>
                <?php if (($visit['visit_kind'] ?? '') === 'CUSTOM'): ?>
                    <div class="vr-form-row">
                        <label class="vr-label" for="title">Titolo visita</label>
                        <input class="vr-input" type="text" id="title" name="title" value="<?php echo vr_h(($visit['title'] ?? '') !== '' ? (string)$visit['title'] : (string)($customForm['name'] ?? '')); ?>" placeholder="Es. Visita ecografica">
                    </div>

                    <?php
                        $cfFields = is_array($customDef['fields'] ?? null) ? $customDef['fields'] : [];
                        $grouped = [];
                        foreach ($cfFields as $f) {
                            if (!is_array($f)) continue;
                            $sec = trim((string)($f['section'] ?? ''));
                            $grouped[$sec][] = $f;
                        }
                        if (empty($grouped)) $grouped = ['' => []];
                    ?>

                    <?php foreach ($grouped as $sec => $fields): ?>
                        <?php if (trim($sec) !== ''): ?>
                            <div class="vr-form-row"><label class="vr-label"><?php echo vr_h($sec); ?></label></div>
                        <?php endif; ?>
                        <?php foreach ($fields as $f): ?>
                            <?php
                                $key = trim((string)($f['key'] ?? ''));
                                $lbl = trim((string)($f['label'] ?? ''));
                                if ($lbl === '') continue;
                                if ($key === '') $key = vr_slugify_key($lbl);
                                $type = strtolower(trim((string)($f['type'] ?? 'text')));
                                $required = !empty($f['required']);
                                $opts = $f['options'] ?? [];
                                if (is_string($opts)) $opts = array_values(array_filter(array_map('trim', explode(',', $opts))));
                                if (!is_array($opts)) $opts = [];
                                $curVal = (string)($customValues[$key] ?? '');
                            ?>
                            <div class="vr-form-row">
                                <label class="vr-label" for="cf_<?php echo vr_h($key); ?>"><?php echo vr_h($lbl); ?></label>
                                <?php if ($type === 'textarea'): ?>
                                    <textarea class="vr-textarea" id="cf_<?php echo vr_h($key); ?>" name="cf[<?php echo vr_h($key); ?>]" <?php echo $required ? 'required' : ''; ?>><?php echo vr_h($curVal); ?></textarea>
                                <?php elseif ($type === 'select'): ?>
                                    <select class="vr-select" id="cf_<?php echo vr_h($key); ?>" name="cf[<?php echo vr_h($key); ?>]" <?php echo $required ? 'required' : ''; ?>>
                                        <option value=""></option>
                                        <?php foreach ($opts as $op): ?>
                                            <option value="<?php echo vr_h($op); ?>" <?php echo ($curVal === (string)$op) ? 'selected' : ''; ?>><?php echo vr_h($op); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                <?php elseif ($type === 'checkbox'): ?>
                                    <label style="display:flex;align-items:center;gap:8px;font-size:13px;">
                                        <input type="checkbox" id="cf_<?php echo vr_h($key); ?>" name="cf[<?php echo vr_h($key); ?>]" value="1" <?php echo (in_array(strtolower(trim($curVal)), ['1','si','sì','true','yes'], true)) ? 'checked' : ''; ?>>
                                        <span>Seleziona</span>
                                    </label>
                                <?php else: ?>
                                    <input class="vr-input" type="<?php echo $type === 'number' ? 'number' : 'text'; ?>" id="cf_<?php echo vr_h($key); ?>" name="cf[<?php echo vr_h($key); ?>]" value="<?php echo vr_h($curVal); ?>" <?php echo $required ? 'required' : ''; ?>>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    <?php endforeach; ?>
                <?php endif; ?>
                <div class="vr-form-row">
                    <label class="vr-label" for="diagnosis">Diagnosi</label>
                    <textarea class="vr-textarea" id="diagnosis" name="diagnosis"><?php echo vr_h($visit['diagnosis']); ?></textarea>
                </div>
                <div class="vr-form-row">
                    <label class="vr-label" for="therapy">Terapia</label>
                    <textarea class="vr-textarea" id="therapy" name="therapy"><?php echo vr_h($visit['therapy']); ?></textarea>
                </div>
                <div class="vr-form-row">
                    <label class="vr-label" for="notes">Note</label>
                    <textarea class="vr-textarea" id="notes" name="notes"><?php echo vr_h($visit['notes']); ?></textarea>
                </div>
                <div style="display:flex;gap:8px;align-items:center;">
                    <button type="submit" class="vr-button">Salva modifiche</button>
                    <a class="vr-button vr-button-secondary" href="index.php?page=visit_show&id=<?php echo (int)$visit['id']; ?>">Apri scheda</a>
                    <a class="vr-button vr-button-secondary" href="index.php?page=pet_detail&id=<?php echo (int)$visit['pet_id']; ?>">Torna all'animale</a>
                </div>
            </form>
        </div>
        <?php
        vr_layout_end();
        break;
    }
case 'visit_show': {
        // Segreteria (e altri ruoli non veterinari) non possono aprire/generare schede visita.
        if (!vr_policy_can('visit_write', $user)) {
            vr_layout_start('Operazione non autorizzata - ' . VETROOM_APP_NAME, $user, 'pets');
            echo '<div class="vr-card"><p style="font-size:13px;margin:0;">Le schede visita sono accessibili solo ai veterinari.</p></div>';
            vr_layout_end();
            break;
        }

        $visit_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
        if (vr_policy_is_admin($user)) {
            $stmt = $db->prepare("
                SELECT v.*, vf.name AS visit_form_name,
                       p.name AS pet_name, p.species AS pet_species,
                       o.name AS owner_name, o.surname AS owner_surname, o.email AS owner_email, o.id AS owner_id
                FROM visits v
                JOIN pets p ON v.pet_id = p.id
                JOIN owners o ON v.owner_id = o.id
                LEFT JOIN visit_forms vf ON vf.id = v.visit_form_id AND vf.clinic_id = v.clinic_id
                WHERE v.id = ? AND v.clinic_id = ?
            ");
        } else {
            $stmt = $db->prepare("
                SELECT v.*, vf.name AS visit_form_name,
                       p.name AS pet_name, p.species AS pet_species,
                       o.name AS owner_name, o.surname AS owner_surname, o.email AS owner_email, o.id AS owner_id
                FROM visits v
                JOIN pets p ON v.pet_id = p.id
                JOIN owners o ON v.owner_id = o.id
                JOIN vet_owner_relations r ON r.owner_id=o.id AND r.clinic_id=o.clinic_id
                LEFT JOIN visit_forms vf ON vf.id = v.visit_form_id AND vf.clinic_id = v.clinic_id
                WHERE v.id = ? AND v.clinic_id = ? AND r.status='active'
            ");
        }
        $stmt->execute([$visit_id, $clinicId]);
        $visit = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$visit) {
            vr_layout_start('Visita non trovata - ' . VETROOM_APP_NAME, $user, 'pets');
            echo '<div class="vr-card"><p style="font-size:13px;margin:0;">Visita non trovata.</p></div>';
            vr_layout_end();
            break;
        }

        $formData = vr_decode_form_data($visit['form_data']);
        $header = vr_get_vet_header($clinicId) ?? [];
    if (!is_array($header)) $header = [];

        // Messaggi azioni (PDF / email)
        $msg_error = '';
        $msg_success = '';

        // Upload allegati alla visita (foto, pdf, ...)
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && ( $_POST['form_action'] ?? '' ) === 'upload_visit_attachment') {
            try {
                if (!isset($_FILES['attachment_file']) || $_FILES['attachment_file']['error'] !== UPLOAD_ERR_OK) {
                    throw new Exception('Seleziona un file valido (foto o PDF).');
                }
                $file = $_FILES['attachment_file'];
                if (($file['size'] ?? 0) > 10 * 1024 * 1024) {
                    throw new Exception('File troppo grande (max 10MB).');
                }
                $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
                $allowedExt = ['pdf','jpg','jpeg','png','gif','webp'];
                if ($ext === '' || !in_array($ext, $allowedExt, true)) {
                    throw new Exception('Formato non supportato (pdf/jpg/png/gif/webp).');
                }
                // Multi-tenant safe path (separate folders per clinic).
                $uploadsDir = __DIR__ . '/data/private_uploads/clinic_' . $clinicId . '/attachments';
                if (!is_dir($uploadsDir)) {
                    @mkdir($uploadsDir, 0775, true);
                }
                $safeName = 'att_visit_' . $visit_id . '_' . time() . '_' . mt_rand(1000, 9999) . '.' . preg_replace('/[^a-z0-9]/i', '', $ext);
                $destPath = $uploadsDir . '/' . $safeName;
                if (!move_uploaded_file($file['tmp_name'], $destPath)) {
                    throw new Exception('Impossibile salvare il file sul server.');
                }
                $mime = '';
                if (class_exists('finfo')) {
                    try {
                        $fi = new finfo(FILEINFO_MIME_TYPE);
                        $mime = (string)$fi->file($destPath);
                    } catch (Throwable $t) {
                        $mime = '';
                    }
                }
                if ($mime === '') {
                    $mime = (string)($file['type'] ?? '');
                }
                $now = vr_now_iso();
                $stmtDoc = $db->prepare("INSERT INTO documents (clinic_id, pet_id, owner_id, visit_id, filename, original_name, mime_type, doc_role, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
                $stmtDoc->execute([
                    $clinicId,
                    (int)($visit['pet_id'] ?? 0),
                    (int)($visit['owner_id'] ?? 0),
                    $visit_id,
                    'data/private_uploads/clinic_' . $clinicId . '/attachments/' . $safeName,
                    $file['name'],
                    $mime,
                    'ATTACHMENT',
                    $now
                ]);
                $msg_success = 'Allegato caricato correttamente.';
            } catch (Exception $e) {
                $msg_error = 'Errore caricamento allegato: ' . $e->getMessage();
            }
        }

        // Eliminazione allegato visita (foto/PDF caricati dalla scheda visita)
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && ( $_POST['form_action'] ?? '' ) === 'delete_visit_attachment') {
            try {
                if ((string)($user['role'] ?? '') === 'READONLY') {
                    throw new Exception('Permesso insufficiente.');
                }
                $docId = (int)($_POST['doc_id'] ?? 0);
                if ($docId <= 0) throw new Exception('Documento non valido.');

                $stmtD = $db->prepare("SELECT * FROM documents WHERE id=? AND clinic_id=? AND visit_id=? LIMIT 1");
                $stmtD->execute([$docId, $clinicId, $visit_id]);
                $doc = $stmtD->fetch(PDO::FETCH_ASSOC) ?: null;
                if (!$doc) throw new Exception('Documento non trovato.');

                $role = (string)($doc['doc_role'] ?? '');
                if ($role !== 'ATTACHMENT') {
                    throw new Exception('Operazione non consentita.');
                }

                $fn = (string)($doc['filename'] ?? '');
                if ($fn !== '') {
                    vr_safe_unlink_rel($fn);
                }
                $db->prepare("DELETE FROM documents WHERE id=? AND clinic_id=?")->execute([$docId, $clinicId]);
                $msg_success = 'Allegato eliminato correttamente.';
            } catch (Exception $e) {
                $msg_error = 'Errore eliminazione allegato: ' . $e->getMessage();
            }
        }

        // Eliminazione PDF scheda visita
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && ( $_POST['form_action'] ?? '' ) === 'delete_visit_pdf') {
            try {
                if ((string)($user['role'] ?? '') === 'READONLY') {
                    throw new Exception('Permesso insufficiente.');
                }
                $docId = (int)($_POST['doc_id'] ?? 0);
                if ($docId <= 0) {
                    throw new Exception('Documento non valido.');
                }

                $stmtD = $db->prepare("SELECT * FROM documents WHERE id=? AND clinic_id=? AND visit_id=? LIMIT 1");
                $stmtD->execute([$docId, $clinicId, $visit_id]);
                $doc = $stmtD->fetch(PDO::FETCH_ASSOC);
                if (!$doc) {
                    throw new Exception('Documento non trovato.');
                }

                // Only allow deleting visit PDF DRAFTs from this page.
                // Definitive PDFs are immutable for medico-legal reasons.
                $role = (string)($doc['doc_role'] ?? '');
                if ($role === 'VISIT_PDF_FINAL') {
                    throw new Exception('Il PDF definitivo non può essere eliminato.');
                }
                if (!in_array($role, ['VISIT_PDF_DRAFT', 'VISIT_PDF'], true)) {
                    throw new Exception('Operazione non consentita.');
                }

                $rel = (string)($doc['filename'] ?? '');
                $rel = ltrim(str_replace('\\', '/', $rel), '/');
                if ($rel !== '' && strpos($rel, '..') === false && strpos($rel, ':') === false) {
                    $abs = __DIR__ . '/' . $rel;
                    $real = realpath($abs);
                    if ($real && is_file($real)) {
                        $allowed = [
                            realpath(__DIR__ . '/data/private_uploads') ?: '',
                            realpath(__DIR__ . '/data/uploads') ?: '', // legacy
                        ];
                        foreach ($allowed as $base) {
                            if ($base !== '' && strncmp($real, $base, strlen($base)) === 0) {
                                @unlink($real);
                                break;
                            }
                        }
                    }
                }

                $db->prepare("DELETE FROM documents WHERE id=? AND clinic_id=?")->execute([$docId, $clinicId]);
                $msg_success = 'PDF eliminato correttamente.';
            } catch (Exception $e) {
                $msg_error = 'Errore eliminazione PDF: ' . $e->getMessage();
            }
        }

        // PDF collegati alla visita (bozza + definitivo)
        $stmtFinalPdf = $db->prepare("SELECT * FROM documents WHERE clinic_id = ? AND visit_id = ? AND doc_role = 'VISIT_PDF_FINAL' ORDER BY created_at DESC LIMIT 1");
        $stmtFinalPdf->execute([$clinicId, $visit_id]);
        $finalPdfDoc = $stmtFinalPdf->fetch(PDO::FETCH_ASSOC) ?: null;

        $stmtDraftPdf = $db->prepare("SELECT * FROM documents WHERE clinic_id = ? AND visit_id = ? AND (doc_role = 'VISIT_PDF_DRAFT' OR doc_role = 'VISIT_PDF') ORDER BY created_at DESC LIMIT 1");
        $stmtDraftPdf->execute([$clinicId, $visit_id]);
        $draftPdfDoc = $stmtDraftPdf->fetch(PDO::FETCH_ASSOC) ?: null;

        // Legacy fallback (pre doc_role)
        if (!$draftPdfDoc) {
            $stmtPdfDoc2 = $db->prepare("SELECT * FROM documents WHERE clinic_id = ? AND visit_id = ? AND mime_type = 'application/pdf' AND (doc_role IS NULL OR doc_role = '') ORDER BY created_at DESC LIMIT 1");
            $stmtPdfDoc2->execute([$clinicId, $visit_id]);
            $draftPdfDoc = $stmtPdfDoc2->fetch(PDO::FETCH_ASSOC) ?: null;
        }

        // Allegati della visita (caricati dal tab visita)
        $stmtAtt = $db->prepare("SELECT * FROM documents WHERE clinic_id = ? AND visit_id = ? AND doc_role = 'ATTACHMENT' ORDER BY created_at ASC");
        $stmtAtt->execute([$clinicId, $visit_id]);
        $visitAttachments = $stmtAtt->fetchAll(PDO::FETCH_ASSOC);

        // Generazione PDF on-demand (BOZZA / DEFINITIVO)
        $pdfAction = (string)($_POST['form_action'] ?? '');
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array($pdfAction, ['generate_visit_pdf', 'generate_visit_pdf_draft', 'generate_visit_pdf_final'], true)) {
            try {
                $headerRow = vr_get_vet_header($clinicId);
                // Pet completo (serve per razza, sesso, stato riproduttivo, peso, microchip, ecc.)
                $stmtPetFull = $db->prepare("SELECT p.*, o.name AS owner_name, o.surname AS owner_surname, o.id AS owner_id FROM pets p JOIN owners o ON p.owner_id = o.id WHERE p.id = ? AND p.clinic_id = ?");
                $stmtPetFull->execute([(int)$visit['pet_id'], $clinicId]);
                $petFull = $stmtPetFull->fetch(PDO::FETCH_ASSOC) ?: [];

                $ownerFull = vr_get_owner($clinicId, (int)($visit['owner_id'] ?? 0)) ?? [];

                $visitRowForPdf = [
                    'owner_surname' => $visit['owner_surname'] ?? ($ownerFull['surname'] ?? ''),
                    'owner_name'    => $visit['owner_name'] ?? ($ownerFull['name'] ?? ''),
                    'owner_id'      => (int)($visit['owner_id'] ?? 0),
                    'visit_date'    => $visit['visit_date'] ?? '',
                    'visit_kind'    => $visit['visit_kind'] ?? 'CLINICA',
                    'visit_form_id' => (int)($visit['visit_form_id'] ?? 0),
                    'visit_form_name' => (string)($visit['visit_form_name'] ?? ''),
                    'title'         => (string)($visit['title'] ?? ''),
                    'diagnosis'     => $visit['diagnosis'] ?? '',
                    'therapy'       => $visit['therapy'] ?? '',
                    'notes'         => $visit['notes'] ?? ''
                ];

                $isFinal = ($pdfAction === 'generate_visit_pdf_final');

                // If generating a new draft, clean previous drafts to avoid clutter.
                if (!$isFinal) {
                    $stOld = $db->prepare("SELECT id, filename FROM documents WHERE clinic_id=? AND visit_id=? AND (doc_role='VISIT_PDF_DRAFT' OR doc_role='VISIT_PDF')");
                    $stOld->execute([$clinicId, $visit_id]);
                    $old = $stOld->fetchAll(PDO::FETCH_ASSOC);
                    foreach ($old as $od) {
                        $rel = (string)($od['filename'] ?? '');
                        if ($rel !== '') {
                            vr_safe_unlink_rel($rel);
                        }
                        $db->prepare("DELETE FROM documents WHERE id=? AND clinic_id=?")->execute([(int)$od['id'], $clinicId]);
                    }
                }

                $pdfRel = vr_generate_visit_pdf($headerRow, $petFull, $visitRowForPdf, $formData, $clinicId, $ownerFull, $visitAttachments, $isFinal);
                if ($pdfRel) {
                    $stmtDoc = $db->prepare("INSERT INTO documents (clinic_id, pet_id, owner_id, visit_id, filename, original_name, mime_type, doc_role, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
                    $nowDoc = vr_now_iso();
                    $stmtDoc->execute([
                        $clinicId,
                        (int)$visit['pet_id'],
                        (int)$visit['owner_id'],
                        $visit_id,
                        $pdfRel,
                        basename($pdfRel),
                        'application/pdf',
                        ($isFinal ? 'VISIT_PDF_FINAL' : 'VISIT_PDF_DRAFT'),
                        $nowDoc
                    ]);
                    $newDocId = (int)$db->lastInsertId();
                    // Redirect diretto al PDF tramite endpoint autenticato
                    header('Location: download.php?doc_id=' . $newDocId . '&inline=1');
                    exit;
                } else {
                    $msg_error = 'Impossibile generare il PDF.';
                }
            } catch (Exception $e) {
                $msg_error = 'Errore generazione PDF: ' . $e->getMessage();
            }
        }

        // Invio email da questa pagina
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && ( $_POST['form_action'] ?? '' ) === 'send_email_visit') {
            if (empty($visit['owner_email'])) {
                $msg_error = 'Email proprietario assente.';
            } else {
                $to = $visit['owner_email'];
                $subject = 'Scheda visita per ' . $visit['pet_name'] . ' del ' . $visit['visit_date'];

                $body = '';
                if ($header) {
                    $body .= $header['header_name'] . "\n";
                    if (!empty($header['header_title'])) $body .= $header['header_title'] . "\n";
                    if (!empty($header['header_albo'])) $body .= $header['header_albo'] . "\n";
                    if (!empty($header['header_phone'])) $body .= 'Tel: ' . $header['header_phone'] . "\n";
                    $body .= "\n";
                }

                $body .= "Animale: " . $visit['pet_name'] . " (" . $visit['pet_species'] . ")\n";
                $body .= "Proprietario: " . $visit['owner_surname'] . " " . $visit['owner_name'] . "\n";
                $body .= "Data visita: " . $visit['visit_date'] . "\n";
                $typeLabel = 'Clinica';
                if (($visit['visit_kind'] ?? '') === 'OFTALMO') {
                    $typeLabel = 'Oftalmologica';
                } elseif (($visit['visit_kind'] ?? '') === 'CUSTOM') {
                    $typeLabel = !empty($visit['visit_form_name']) ? $visit['visit_form_name'] : 'Visita personalizzata';
                }
                $body .= "Tipo visita: " . $typeLabel . "\n\n";

                if ($visit['visit_kind'] === 'CLINICA') {
                    $body .= "Motivo della visita:\n" . ($formData['reason'] ?? '') . "\n\n";
                    $body .= "Anamnesi:\n" . ($formData['anamnesis'] ?? '') . "\n\n";
                    $body .= "Esame obiettivo:\n" . (function($obj){ $out=''; if(is_array($obj)){ foreach($obj as $k=>$v){ if(trim((string)$v)!==''){ $out .= ' - ' . $k . ': ' . $v . "\n"; } } } return $out; })($formData['objective'] ?? []) . "\n";
                } elseif ($visit['visit_kind'] === 'CUSTOM') {
                    $body .= "Dettagli visita:\n";
                    if (!empty($formData['_custom_form']['fields']) && is_array($formData['_custom_form']['fields'])) {
                        foreach ($formData['_custom_form']['fields'] as $f) {
                            $label = trim((string)($f['label'] ?? ''));
                            $val = trim((string)($f['value'] ?? ''));
                            if ($label !== '' && $val !== '') {
                                $body .= " - " . $label . ": " . $val . "\n";
                            }
                        }
                    } else {
                        foreach ($formData as $k => $v) {
                            if (is_string($v) && trim($v) !== '' && $k !== '') {
                                $body .= " - " . $k . ": " . $v . "\n";
                            }
                        }
                    }
                    $body .= "\n";
                } else {
                    $body .= "Anamnesi:\n" . ($formData['anamnesis'] ?? '') . "\n\n";
                    if (!empty($formData['reflex'])) {
                        $body .= "Riflessi:\n";
                        foreach ($formData['reflex'] as $k => $v) {
                            $body .= " - " . $k . ": " . $v . "\n";
                        }
                        $body .= "\n";
                    }
                    if (!empty($formData['annessi'])) {
                        $body .= "Esami degli annessi:\n";
                        foreach ($formData['annessi'] as $k => $v) {
                            $body .= " - " . $k . ": " . $v . "\n";
                        }
                        $body .= "\n";
                    }
                    if (!empty($formData['occhio'])) {
                        $body .= "Esami occhio:\n";
                        foreach ($formData['occhio'] as $k => $v) {
                            $body .= " - " . $k . ": " . $v . "\n";
                        }
                        $body .= "\n";
                    }
                }

                $body .= "Diagnosi:\n" . ($visit['diagnosis'] ?? '') . "\n\n";
                $body .= "Terapia:\n" . ($visit['therapy'] ?? '') . "\n\n";
                $body .= "Note:\n" . ($visit['notes'] ?? '') . "\n";

                $headers = 'From: VetRoom <' . VETROOM_MAIL_FROM . '>' . "\r\n";
                @mail($to, $subject, $body, $headers);
                $msg_success = 'Scheda inviata via email a ' . vr_h($to) . '.';
            }
        }

        if (($visit['visit_kind'] ?? '') === 'OFTALMO') {
            $titleVisit = 'Scheda visita oftalmologica';
        } elseif (($visit['visit_kind'] ?? '') === 'CUSTOM') {
            $label = !empty($visit['visit_form_name']) ? $visit['visit_form_name'] : 'Visita personalizzata';
            $titleVisit = 'Scheda visita: ' . $label;
        } else {
            $titleVisit = 'Scheda visita clinica';
        }

        vr_layout_start($titleVisit . ' - ' . VETROOM_APP_NAME, $user, 'pets');
        ?>
        <h1 class="vr-page-title"><?php echo vr_h($titleVisit); ?></h1>
        <p class="vr-page-subtitle">Animale: <?php echo vr_h($visit['pet_name']); ?> – Proprietario: <?php echo vr_h($visit['owner_surname'] . ' ' . $visit['owner_name']); ?></p>

        <div class="vr-card">
            <?php if ($msg_error): ?>
                <div class="vr-alert vr-alert-error"><?php echo vr_h($msg_error); ?></div>
            <?php endif; ?>
            <?php if ($msg_success): ?>
                <div class="vr-alert vr-alert-success"><?php echo vr_h($msg_success); ?></div>
            <?php endif; ?>

            <p style="font-size:13px;margin:0 0 6px;">
                <strong>Data visita:</strong> <?php echo vr_h($visit['visit_date']); ?><br>
                <strong>Tipo visita:</strong> <?php
                    if (($visit['visit_kind'] ?? '') === 'OFTALMO') {
                        echo 'Oftalmologica';
                    } elseif (($visit['visit_kind'] ?? '') === 'CUSTOM') {
                        echo vr_h($visit['visit_form_name'] ?: 'Visita personalizzata');
                    } else {
                        echo 'Clinica';
                    }
                ?>
            </p>

            <div style="display:flex;gap:8px;align-items:center;margin:6px 0 12px;flex-wrap:wrap;">
                <?php if (!empty($finalPdfDoc['id'])): ?>
                    <a class="vr-button" href="download.php?doc_id=<?php echo (int)$finalPdfDoc['id']; ?>&inline=1" target="_blank" rel="noopener">Apri PDF definitivo</a>
                <?php endif; ?>
                <?php if (!empty($draftPdfDoc['id'])): ?>
                    <a class="vr-button vr-button-secondary" href="download.php?doc_id=<?php echo (int)$draftPdfDoc['id']; ?>&inline=1" target="_blank" rel="noopener">Apri PDF bozza</a>
                <?php endif; ?>

                <form method="post" target="_blank" style="margin:0;">
                    <input type="hidden" name="form_action" value="generate_visit_pdf_draft">
                    <?php echo vr_staff_csrf_field(); ?>
                    <button type="submit" class="vr-button">
                        <?php echo !empty($draftPdfDoc['id']) ? 'Rigenera PDF bozza' : 'Genera PDF bozza'; ?><?php echo !empty($visitAttachments) ? ' (includi allegati)' : ''; ?>
                    </button>
                </form>

                <form method="post" target="_blank" style="margin:0;">
                    <input type="hidden" name="form_action" value="generate_visit_pdf_final">
                    <?php echo vr_staff_csrf_field(); ?>
                    <button type="submit" class="vr-button" onclick="return confirm('Generare un PDF definitivo? Non potrà essere eliminato.');">
                        Genera PDF definitivo
                    </button>
                </form>
            </div>
            <p style="font-size:12px;color:#666;margin:0 0 10px;">
                Suggerimento: carica <strong>foto</strong> e <strong>PDF</strong> sotto in "Allegati". Le <strong>immagini</strong> verranno aggiunte come <strong>pagine successive</strong> nel PDF.
                I <strong>PDF</strong> allegati verranno elencati in una pagina riepilogativa (l'importazione dentro il PDF dipende dalle librerie disponibili sul server).
            </p>

            <?php if ($visit['visit_kind'] === 'CLINICA'): ?>
                <h3 style="font-size:13px;margin:10px 0 4px;">Motivo della visita</h3>
                <p style="font-size:13px;white-space:pre-wrap;"><?php echo vr_h($formData['reason'] ?? ''); ?></p>

                <h3 style="font-size:13px;margin:10px 0 4px;">Anamnesi</h3>
                <p style="font-size:13px;white-space:pre-wrap;"><?php echo vr_h($formData['anamnesis'] ?? ''); ?></p>

                <h3 style="font-size:13px;margin:10px 0 4px;">Esame obiettivo</h3>
                <?php if (!empty($formData['objective']) && is_array($formData['objective'])): ?>
                    <ul style="font-size:13px;margin:0 0 6px 16px;padding:0;">
                        <?php foreach ($formData['objective'] as $k => $v): if (trim((string)$v) === '') continue; ?>
                            <li><strong><?php echo vr_h($k); ?>:</strong> <?php echo vr_h($v); ?></li>
                        <?php endforeach; ?>
                    </ul>
                <?php else: ?>
                    <p style="font-size:13px;white-space:pre-wrap;"><?php echo vr_h($formData['objective_exam'] ?? ''); ?></p>
                <?php endif; ?>
            <?php elseif ($visit['visit_kind'] === 'CUSTOM'): ?>
                <h3 style="font-size:13px;margin:10px 0 4px;">Dettagli visita</h3>
                <?php
                    $cfields = [];
                    if (!empty($formData['_custom_form']['fields']) && is_array($formData['_custom_form']['fields'])) {
                        $cfields = $formData['_custom_form']['fields'];
                    }
                ?>
                <?php if (empty($cfields)): ?>
                    <p style="font-size:13px;margin:0;color:#666;">Nessun campo personalizzato registrato per questa visita.</p>
                <?php else: ?>
                    <?php
                        $lastSection = '';
                        foreach ($cfields as $f) {
                            $label = trim((string)($f['label'] ?? ''));
                            $value = (string)($f['value'] ?? '');
                            if ($label === '') continue;
                            if (trim($value) === '') continue;
                            $sec = trim((string)($f['section'] ?? ''));
                            if ($sec !== '' && $sec !== $lastSection) {
                                echo '<h4 style="font-size:12px;margin:10px 0 4px;color:#374151;">' . vr_h($sec) . '</h4>';
                                $lastSection = $sec;
                            }
                            echo '<div style="font-size:13px;margin:0 0 6px;"><strong>' . vr_h($label) . ':</strong> ' . nl2br(vr_h($value)) . '</div>';
                        }
                    ?>
                <?php endif; ?>
            <?php else: ?>
                <h3 style="font-size:13px;margin:10px 0 4px;">Anamnesi</h3>
                <p style="font-size:13px;white-space:pre-wrap;"><?php echo vr_h($formData['anamnesis'] ?? ''); ?></p>

                <?php if (!empty($formData['reflex'])): ?>
                    <h3 style="font-size:13px;margin:10px 0 4px;">Riflessi</h3>
                    <ul style="font-size:13px;margin:0 0 6px 16px;padding:0;">
                        <?php foreach ($formData['reflex'] as $k => $v): ?>
                            <li><strong><?php echo vr_h($k); ?>:</strong> <?php echo vr_h($v); ?></li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>

                <?php if (!empty($formData['annessi'])): ?>
                    <h3 style="font-size:13px;margin:10px 0 4px;">Esami degli annessi</h3>
                    <ul style="font-size:13px;margin:0 0 6px 16px;padding:0;">
                        <?php foreach ($formData['annessi'] as $k => $v): ?>
                            <li><strong><?php echo vr_h($k); ?>:</strong> <?php echo vr_h($v); ?></li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>

                <?php if (!empty($formData['occhio'])): ?>
                    <h3 style="font-size:13px;margin:10px 0 4px;">Esami occhio</h3>
                    <ul style="font-size:13px;margin:0 0 6px 16px;padding:0;">
                        <?php foreach ($formData['occhio'] as $k => $v): ?>
                            <li><strong><?php echo vr_h($k); ?>:</strong> <?php echo vr_h($v); ?></li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            <?php endif; ?>

            <h3 style="font-size:13px;margin:10px 0 4px;">Diagnosi</h3>
            <p style="font-size:13px;white-space:pre-wrap;"><?php echo vr_h($visit['diagnosis']); ?></p>

            <h3 style="font-size:13px;margin:10px 0 4px;">Terapia</h3>
            <p style="font-size:13px;white-space:pre-wrap;"><?php echo vr_h($visit['therapy']); ?></p>

            <h3 style="font-size:13px;margin:10px 0 4px;">Note</h3>
            <p style="font-size:13px;white-space:pre-wrap;"><?php echo vr_h($visit['notes']); ?></p>

            <hr style="border:none;border-top:1px solid #e5e7eb;margin:14px 0;">
            <h3 style="font-size:13px;margin:0 0 8px;">Allegati</h3>
            <form method="post" enctype="multipart/form-data" style="margin:0 0 12px;">
                <input type="hidden" name="form_action" value="upload_visit_attachment">
                <?php echo vr_staff_csrf_field(); ?>
                <div class="vr-form-row" style="margin:0;">
                    <label class="vr-label" for="attachment_file" style="min-width:160px;">Carica (foto/PDF)</label>
                    <input class="vr-input" type="file" id="attachment_file" name="attachment_file" accept="image/*,application/pdf">
                </div>
                <button type="submit" class="vr-button" style="margin-top:8px;">Carica allegato</button>
            </form>

            <?php if (empty($visitAttachments)): ?>
                <p style="font-size:13px;margin:0;color:#666;">Nessun allegato caricato per questa visita.</p>
            <?php else: ?>
                <table class="vr-table" style="margin:0;">
                    <thead>
                    <tr>
                        <th>File</th>
                        <th>Tipo</th>
                        <th>Azioni</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($visitAttachments as $att): ?>
                        <tr>
                            <td><?php echo vr_h($att['original_name'] ?: basename((string)$att['filename'])); ?></td>
                            <td><?php echo vr_h($att['mime_type'] ?: ''); ?></td>
                            <td>
                                <a class="vr-button vr-button-secondary" style="font-size:11px;padding:3px 8px;" href="download.php?doc_id=<?php echo (int)$att['id']; ?>&inline=1" target="_blank" rel="noopener">Apri</a>
                                <?php if ((string)($user['role'] ?? '') !== 'READONLY'): ?>
                                    <form method="post" style="display:inline;margin-left:6px;" onsubmit="return confirm('Eliminare questo allegato?');">
                                        <input type="hidden" name="form_action" value="delete_visit_attachment">
                                        <?php echo vr_staff_csrf_field(); ?>
                                        <input type="hidden" name="doc_id" value="<?php echo (int)$att['id']; ?>">
                                        <button type="submit" class="vr-button vr-button-danger vr-icon-submit"><img class="vr-action-icon vr-btn-ico" src="public/icons/delete_visit_attachment.png" alt="Elimina"><span class="vr-btn-label">Elimina</span></button>
                                    </form>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                <p style="font-size:12px;color:#666;margin:8px 0 0;">Nota: quando generi/rigeneri il PDF, le immagini verranno aggiunte come pagine finali.</p>
            <?php endif; ?>

            <form method="post" style="margin-top:10px;">
                <input type="hidden" name="form_action" value="send_email_visit">
                <?php echo vr_staff_csrf_field(); ?>
                <button type="submit" class="vr-button" <?php echo empty($visit['owner_email']) ? 'disabled' : ''; ?>>Invia scheda via email</button>
                <a href="index.php?page=pet_detail&id=<?php echo (int)$visit['pet_id']; ?>" class="vr-button vr-button-secondary" style="margin-left:6px;">Torna alla scheda animale</a>
            </form>
        </div>
        <?php
        vr_layout_end();
        break;
    }

    /* CLINICHE: multi-clinic secretary workspace */
    case 'clinics': {
        $roleUp = strtoupper((string)($user['role'] ?? ''));
        if ($roleUp !== 'SECRETARY' && $roleUp !== 'STAFF') {
            vr_layout_start('Cliniche - ' . VETROOM_APP_NAME, $user, $page);
            echo '<div class="vr-card"><p style="font-size:13px;margin:0;">Pagina non disponibile per il tuo ruolo.</p></div>';
            vr_layout_end();
            break;
        }

        $uid = (int)($user['id'] ?? 0);
        $maxClinics = 1;
        try {
            $stMax = $db->prepare("SELECT COALESCE(max_clinics,1) FROM users WHERE id=? LIMIT 1");
            $stMax->execute([$uid]);
            $maxClinics = max(1, (int)($stMax->fetchColumn() ?: 1));
        } catch (Throwable $t) {
            $maxClinics = (int)($user['max_clinics'] ?? 1);
        }

        // Load memberships (secretary roles)
        $stM = $db->prepare("SELECT uc.clinic_id, uc.role, uc.is_active AS mem_active,
                                    c.name AS clinic_name, c.status AS clinic_status, c.is_active AS clinic_is_active
                             FROM user_clinic uc
                             JOIN clinics c ON c.id = uc.clinic_id
                             WHERE uc.user_id=? AND UPPER(uc.role) IN ('SECRETARY','STAFF')
                             ORDER BY c.name ASC");
        $stM->execute([$uid]);
        $memberships = $stM->fetchAll(PDO::FETCH_ASSOC);

        $activeMemberships = array_values(array_filter($memberships, function($r) {
            $memActive = (int)($r['mem_active'] ?? 0);
            $cActive = (int)($r['clinic_is_active'] ?? 0);
            $cst = strtoupper((string)($r['clinic_status'] ?? ''));
            return $memActive === 1 && $cActive === 1 && $cst !== 'SUSPENDED' && $cst !== 'REVOKED' && $cst !== 'PENDING';
        }));
        $activeCount = count($activeMemberships);

        // Last generated link/QR (PRG-safe: stored in session and shown once).
        $generatedLink = '';
        $generatedQr = '';
        if (!empty($_SESSION['vr_membership_generated']) && is_array($_SESSION['vr_membership_generated'])) {
            $g = $_SESSION['vr_membership_generated'];
            $generatedLink = (string)($g['link'] ?? '');
            $generatedQr = (string)($g['qr'] ?? '');
            unset($_SESSION['vr_membership_generated']);
        }

        if ($_SERVER['REQUEST_METHOD'] === 'POST' && (string)($_POST['form_action'] ?? '') === 'generate_membership_token') {
            if (!vr_staff_csrf_is_valid()) {
                $_SESSION['flash'] = ['type'=>'error','msg'=>'Sessione scaduta. Ricarica la pagina e riprova.'];
                header('Location: index.php?page=clinics');
                exit;
            }

            if ($activeCount >= $maxClinics) {
                $_SESSION['flash'] = ['type'=>'error','msg'=>'Hai già raggiunto il numero massimo di cliniche ('.$activeCount.'/'.$maxClinics.').'];
                header('Location: index.php?page=clinics');
                exit;
            }

            $chiefEmail = strtolower(trim((string)($_POST['chief_email'] ?? '')));
            if ($chiefEmail === '' || !filter_var($chiefEmail, FILTER_VALIDATE_EMAIL)) {
                $_SESSION['flash'] = ['type'=>'error','msg'=>'Inserisci una email CHIEF valida.'];
                header('Location: index.php?page=clinics');
                exit;
            }

            $token = bin2hex(random_bytes(16));
            $hash = hash('sha256', $token);
            // Keep tokens short-lived to reduce risk when shared as QR.
            $expiresAt = (new DateTimeImmutable('+2 days'))->format('c');
            $now = vr_now_iso();
            $db->prepare("INSERT INTO secretary_membership_tokens (secretary_user_id, chief_email, token_hash, expires_at, created_at) VALUES (?,?,?,?,?)")
               ->execute([$uid, $chiefEmail, $hash, $expiresAt, $now]);

            // Build absolute link for sharing / QR
            
            // Build absolute link for sharing / QR
            $generatedLink = vr_app_url('membership.php?token=' . urlencode($token));
            $generatedQr = vr_qr_url($generatedLink, 220);

            // PRG: store once and redirect to avoid accidental re-submission.
            $_SESSION['vr_membership_generated'] = ['link' => $generatedLink, 'qr' => $generatedQr];
            $_SESSION['flash'] = ['type'=>'success','msg'=>'Link generato (valido 2 giorni, 1 solo utilizzo).'];
            header('Location: index.php?page=clinics');
            exit;
        }

        vr_layout_start('Cliniche - ' . VETROOM_APP_NAME, $user, $page);

        if (!empty($_SESSION['flash'])) {
            $f = $_SESSION['flash'];
            $cls = ($f['type'] ?? 'success') === 'error' ? 'vr-alert-error' : 'vr-alert-success';
            echo '<div class="vr-alert ' . $cls . '" style="margin:0 0 12px;">' . vr_h((string)($f['msg'] ?? '')) . '</div>';
            unset($_SESSION['flash']);
        }
        ?>
        <div class="vr-card">
            <h2 style="margin:0 0 6px;">Cliniche</h2>
            <p style="margin:0;color:#555;font-size:13px;">
                Cliniche attive: <strong><?php echo (int)$activeCount; ?></strong> / <strong><?php echo (int)$maxClinics; ?></strong>
            </p>

            <div style="margin-top:12px;">
                <h3 style="margin:0 0 6px;font-size:13px;">Le tue cliniche</h3>
                <?php if (empty($activeMemberships)): ?>
                    <p style="margin:0;color:#666;font-size:13px;">Nessuna clinica attiva associata.</p>
                <?php else: ?>
                    <table class="vr-table" style="margin:0;">
                        <thead>
                        <tr>
                            <th>Clinica</th>
                            <th>Stato</th>
                            <th>Attiva</th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($activeMemberships as $m):
                            $cid = (int)($m['clinic_id'] ?? 0);
                            $cname = (string)($m['clinic_name'] ?? ('Clinica #' . $cid));
                            ?>
                            <tr>
                                <td><?php echo vr_h($cname); ?></td>
                                <td><?php echo vr_h(strtoupper((string)($m['clinic_status'] ?? 'ACTIVE'))); ?></td>
                                <td><?php echo ($cid === (int)$user['clinic_id']) ? '<strong>SI</strong>' : 'NO'; ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>

            <hr style="border:none;border-top:1px solid #e5e7eb;margin:14px 0;">

            <div>
                <h3 style="margin:0 0 6px;font-size:13px;">Aggiungi una clinica</h3>
<?php if ($maxClinics <= 1): ?>
    <p class="vr-muted" style="margin:0;">Il tuo profilo è <strong>monoclinica</strong> (max 1 clinica). Puoi affiliarti ad <strong>una sola</strong> clinica.</p>
<?php endif; ?>

<?php if ($activeCount >= $maxClinics): ?>
    <p class="vr-muted" style="margin:0;">Hai raggiunto il numero massimo di cliniche.</p>
<?php else: ?>
    <p class="vr-muted" style="margin:0 0 10px 0;">Genera un link (o QR) per chiedere al CHIEF di attivare la tua membership tramite OTP.</p>

    <form method="post" action="index.php?page=clinics" style="display:flex;gap:10px;flex-wrap:wrap;align-items:end;">
        <?php echo vr_staff_csrf_field(); ?>
        <input type="hidden" name="form_action" value="generate_membership_token">
        <div style="min-width:260px;flex:1;">
            <label style="display:block;font-size:12px;color:#555;margin:0 0 6px 2px;">Email CHIEF (che attiverà)</label>
            <input name="chief_email" type="email" class="vr-input" placeholder="chief@clinica.it" required>
        </div>
        <div>
            <button type="submit" class="vr-btn vr-btn-primary">Genera link di affiliazione</button>
        </div>
    </form>

    <?php if ($generatedLink): ?>
        <div style="margin-top:12px; padding:10px; border:1px solid #e5e7eb; border-radius:10px;">
            <div style="display:flex; gap:16px; align-items:flex-start; flex-wrap:wrap;">
                <div style="flex:1; min-width:260px;">
                    <div class="vr-muted" style="margin-bottom:6px;">Link membership (valido 2 giorni)</div>
                    <div style="word-break:break-all; font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace; font-size:12px;">
                        <?php echo vr_h($generatedLink); ?>
                    </div>
                    <div style="margin-top:8px;">
                        <a class="vr-btn vr-btn-secondary" href="<?php echo vr_h($generatedLink); ?>" target="_blank" rel="noopener">Apri link</a>
                    </div>
                </div>
                <div style="width:220px; text-align:center;">
                    <div class="vr-muted" style="margin-bottom:6px;">QR</div>
                    <img src="<?php echo vr_h($generatedQr); ?>" alt="QR membership" style="width:220px; height:220px; border:1px solid #e5e7eb; border-radius:10px;">
                </div>
            </div>
        </div>
    <?php endif; ?>
<?php endif; ?>
            </div>
        </div>
        <?php
        vr_layout_end();
        break;
    }

    /* OPZIONI: disponibilità agenda */
    case 'settings': {
        $settings_error = '';
        $settings_success = '';

        // Backup (export/import full snapshot)
        require_once __DIR__ . '/lib/backup.php';
        $backup_error = '';
        $backup_success = '';
        $isAdmin = vr_policy_is_admin($user);

        // PDF engine (scheda visita) settings
        $pdf_error = '';
        $pdf_success = '';

        // Gestione intestazione professionista
        $header_error = '';
        $header_success = '';

        // Ensure a vet_settings row exists for this clinic (some updates use UPDATE without prior INSERT).
        $ensure_vet_settings_row = function() use ($db, $clinicId) {
            $stmtChk = $db->prepare("SELECT id FROM vet_settings WHERE clinic_id = ? LIMIT 1");
            $stmtChk->execute([$clinicId]);
            $id = $stmtChk->fetchColumn();
            if ($id) return;
            $now = vr_now_iso();
            $stmtIns = $db->prepare("INSERT INTO vet_settings (clinic_id, created_at, updated_at) VALUES (?, ?, ?)");
            $stmtIns->execute([$clinicId, $now, $now]);
        };
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && ( $_POST['form_action'] ?? '' ) === 'save_header') {
            try {
                $header_name   = trim($_POST['header_name'] ?? '');
                $header_title  = trim($_POST['header_title'] ?? '');
                $header_piva   = trim($_POST['header_piva'] ?? '');
                $header_albo   = trim($_POST['header_albo'] ?? '');
                $header_phone  = trim($_POST['header_phone'] ?? '');
                $header_email  = trim($_POST['header_email'] ?? '');
                $header_address= trim($_POST['header_address'] ?? '');
                if ($header_name === '') throw new Exception('Il campo Nome è obbligatorio.');

                $now = vr_now_iso();
                // esiste riga?
                $stmtChk = $db->prepare("SELECT id FROM vet_settings WHERE clinic_id = ? LIMIT 1");
                $stmtChk->execute([$clinicId]);
                if ($stmtChk->fetchColumn()) {
                    $stmtUp = $db->prepare("
                        UPDATE vet_settings
                           SET header_name = ?, header_title = ?, header_piva = ?, header_albo = ?, header_phone = ?, header_email = ?, header_address = ?, updated_at = ?
                         WHERE clinic_id = ?
                    ");
                    $stmtUp->execute([$header_name, $header_title, $header_piva, $header_albo, $header_phone, $header_email, $header_address, $now, $clinicId]);
                } else {
                    $stmtIns = $db->prepare("
                        INSERT INTO vet_settings (clinic_id, header_name, header_title, header_piva, header_albo, header_phone, header_email, header_address, created_at, updated_at)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                    ");
                    $stmtIns->execute([$clinicId, $header_name, $header_title, $header_piva, $header_albo, $header_phone, $header_email, $header_address, $now, $now]);
                }
                $header_success = 'Intestazione salvata.';
            } catch (Exception $e) {
                $header_error = 'Errore salvataggio intestazione: ' . $e->getMessage();
            }
        }

        // Upload logo
        $logo_error = '';
        $logo_success = '';
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && ( $_POST['form_action'] ?? '' ) === 'upload_logo') {
            try {
                if (!isset($_FILES['logo_file']) || $_FILES['logo_file']['error'] !== UPLOAD_ERR_OK) {
                    throw new Exception('Seleziona un file immagine valido.');
                }
                $tmp = $_FILES['logo_file']['tmp_name'];
                $data = file_get_contents($tmp);
                if ($data === false) throw new Exception('Impossibile leggere il file caricato.');
                $uploadDirAbs = __DIR__ . '/data/uploads';
                if (!is_dir($uploadDirAbs)) @mkdir($uploadDirAbs, 0775, true);
                $dest = $uploadDirAbs . '/logo_clinic_' . $clinicId . '.jpg';

                // Convert to JPEG if possible
                $ok = false;
                if (function_exists('imagecreatefromstring')) {
                    $im = @imagecreatefromstring($data);
                    if ($im) {
                        $ok = imagejpeg($im, $dest, 90);
                        imagedestroy($im);
                    }
                }
                if (!$ok) {
                    // Fallback: just move original
                    $ok = move_uploaded_file($tmp, $dest);
                }
                if (!$ok) throw new Exception('Impossibile salvare il logo.');

                $rel = 'data/uploads/' . basename($dest);
                $ensure_vet_settings_row();
                $now = vr_now_iso();
                $stmtUpLogo = $db->prepare("UPDATE vet_settings SET logo_path = ?, updated_at = ? WHERE clinic_id = ?");
                $stmtUpLogo->execute([$rel, $now, $clinicId]);

                $logo_success = 'Logo aggiornato.';
            } catch (Exception $e) {
                $logo_error = 'Errore upload logo: ' . $e->getMessage();
            }
        }

        // Upload stamp / timbro (image)
        $stamp_error = '';
        $stamp_success = '';
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && ( $_POST['form_action'] ?? '' ) === 'upload_stamp') {
            try {
                if (!isset($_FILES['stamp_file']) || $_FILES['stamp_file']['error'] !== UPLOAD_ERR_OK) {
                    throw new Exception('Seleziona un file immagine valido.');
                }
                $tmp = $_FILES['stamp_file']['tmp_name'];
                $data = file_get_contents($tmp);
                if ($data === false) throw new Exception('Impossibile leggere il file caricato.');
                $uploadDirAbs = __DIR__ . '/data/uploads';
                if (!is_dir($uploadDirAbs)) @mkdir($uploadDirAbs, 0775, true);
                $dest = $uploadDirAbs . '/stamp_clinic_' . $clinicId . '.jpg';

                // Convert to JPEG if possible
                $ok = false;
                if (function_exists('imagecreatefromstring')) {
                    $im = @imagecreatefromstring($data);
                    if ($im) {
                        $ok = imagejpeg($im, $dest, 90);
                        imagedestroy($im);
                    }
                }
                if (!$ok) {
                    $ok = move_uploaded_file($tmp, $dest);
                }
                if (!$ok) throw new Exception('Impossibile salvare il timbro.');

                $rel = 'data/uploads/' . basename($dest);
                $ensure_vet_settings_row();
                $now = vr_now_iso();
                $stmtUp = $db->prepare("UPDATE vet_settings SET stamp_path = ?, updated_at = ? WHERE clinic_id = ?");
                $stmtUp->execute([$rel, $now, $clinicId]);

                $stamp_success = 'Timbro aggiornato.';
            } catch (Exception $e) {
                $stamp_error = 'Errore upload timbro: ' . $e->getMessage();
            }
        }



        // Template schede visita (caricamento + mappatura per ogni scheda)
        $tpl_error = '';
        $tpl_success = '';

        require_once __DIR__ . '/lib/visit_templates_repo.php';
        require_once __DIR__ . '/lib/visit_template_maps.php';

        $validate_sheet = function(string $raw) use ($db, $clinicId): array {
            $key = vr_visit_sheet_key_normalize($raw);
            if ($key === 'clinica') {
                return ['key' => 'clinica', 'kind' => 'clinica', 'title' => 'Clinica', 'form' => null];
            }
            if ($key === 'oftalmo' || $key === 'oftalmologica') {
                return ['key' => 'oftalmo', 'kind' => 'oftalmo', 'title' => 'Oftalmologica', 'form' => null];
            }
            if ($key === 'custom') {
                return ['key' => 'custom', 'kind' => 'custom', 'title' => 'Personalizzata', 'form' => null];
            }
            if (strpos($key, 'form:') === 0) {
                $id = (int)substr($key, 5);
                if ($id <= 0) throw new Exception('Scheda visita non valida.');
                $st = $db->prepare("SELECT id, name, definition_json FROM visit_forms WHERE clinic_id = ? AND id = ?");
                $st->execute([$clinicId, $id]);
                $form = $st->fetch(PDO::FETCH_ASSOC) ?: null;
                if (!$form) throw new Exception('Modulo visita non trovato.');
                return ['key' => 'form:' . $id, 'kind' => 'custom', 'title' => (string)($form['name'] ?? 'Modulo'), 'form' => $form];
            }
            throw new Exception('Scheda visita non valida.');
        };

        // --- EXPORT template (ZIP) ---
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && ( $_POST['form_action'] ?? '' ) === 'export_visit_template') {
            try {
                $sheet = $validate_sheet((string)($_POST['template_sheet_key'] ?? ($_POST['template_kind'] ?? 'clinica')));
                $sheetKey = $sheet['key'];
                $safeKey = vr_visit_sheet_key_to_filename($sheetKey);

                $zipAvailable = class_exists('ZipArchive');

                $cfg = vr_visit_template_get($db, $clinicId, $sheetKey) ?: [];
                $enabled = (int)($cfg['enabled'] ?? 1);
                $overlay = (int)($cfg['overlay_header'] ?? 0);
                $bgRel = trim((string)($cfg['bg_path'] ?? ''));
                $mapJson = trim((string)($cfg['map_json'] ?? ''));

                if ($mapJson === '') {
                    if ($sheetKey === 'oftalmo') {
                        $mapJson = vr_visit_template_default_map_json('oftalmologica');
                    } elseif ($sheetKey === 'clinica') {
                        $mapJson = vr_visit_template_default_map_json('clinica');
                    } else {
                        $mapJson = vr_visit_template_default_map_custom_form_json((string)($sheet['title'] ?? 'Visita personalizzata'));
                    }
                }

                $bgAbs = '';
                if ($bgRel !== '') {
                    $cand = __DIR__ . '/' . ltrim($bgRel, '/');
                    if (file_exists($cand)) $bgAbs = $cand;
                }

                // If ZipArchive is not available, fall back to exporting a single JSON bundle.
                // This keeps export/import usable even on shared hostings without the zip extension.
                if (!$zipAvailable) {
                    $bundle = [
                        'vetroom_template_export_version' => 1,
                        'exported_at' => vr_now_iso(),
                        'visit_key' => $sheetKey,
                        'visit_title' => (string)($sheet['title'] ?? ''),
                        'enabled' => $enabled,
                        'overlay_header' => $overlay,
                        'map_json' => $mapJson,
                    ];
                    if ($bgAbs !== '') {
                        $rawBg = @file_get_contents($bgAbs);
                        if ($rawBg !== false) {
                            $ext = strtolower(pathinfo($bgAbs, PATHINFO_EXTENSION));
                            if ($ext === 'jpeg') $ext = 'jpg';
                            if ($ext === '') $ext = 'png';
                            $bundle['background_ext'] = $ext;
                            $bundle['background_base64'] = base64_encode($rawBg);
                        }
                    }

                    $dlName = 'vetroom_template_' . $safeKey . '_' . date('Ymd_His') . '.json';
                    header('Content-Type: application/json; charset=utf-8');
                    header('Content-Disposition: attachment; filename="' . $dlName . '"');
                    header('X-Content-Type-Options: nosniff');
                    echo json_encode($bundle, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                    exit;
                }

                $tmpBase = tempnam(sys_get_temp_dir(), 'vr_tpl_');
                if (!$tmpBase) throw new Exception('Impossibile creare un file temporaneo.');
                $zipPath = $tmpBase . '.zip';
                @rename($tmpBase, $zipPath);

                $zip = new ZipArchive();
                if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
                    throw new Exception('Impossibile creare il file ZIP.');
                }

                $manifest = [
                    'vetroom_template_export_version' => 1,
                    'exported_at' => vr_now_iso(),
                    'visit_key' => $sheetKey,
                    'visit_title' => (string)($sheet['title'] ?? ''),
                    'enabled' => $enabled,
                    'overlay_header' => $overlay,
                ];

                $zip->addFromString('template.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
                $zip->addFromString('template_map.json', $mapJson);

                if ($bgAbs !== '') {
                    $ext = strtolower(pathinfo($bgAbs, PATHINFO_EXTENSION));
                    if ($ext === '') $ext = 'png';
                    $zip->addFile($bgAbs, 'background.' . $ext);
                }

                $zip->close();

                $dlName = 'vetroom_template_' . $safeKey . '_' . date('Ymd_His') . '.zip';
                header('Content-Type: application/zip');
                header('Content-Disposition: attachment; filename="' . $dlName . '"');
                header('X-Content-Type-Options: nosniff');
                header('Content-Length: ' . filesize($zipPath));
                readfile($zipPath);
                @unlink($zipPath);
                exit;
            } catch (Exception $e) {
                $tpl_error = 'Errore export template: ' . $e->getMessage();
            }
        }

        // --- IMPORT template (ZIP/JSON) ---
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && ( $_POST['form_action'] ?? '' ) === 'import_visit_template') {
            try {
                $sheet = $validate_sheet((string)($_POST['template_sheet_key'] ?? ($_POST['template_kind'] ?? 'clinica')));
                $sheetKey = $sheet['key'];
                $safeKey = vr_visit_sheet_key_to_filename($sheetKey);

                if (!isset($_FILES['template_import_file']) || $_FILES['template_import_file']['error'] !== UPLOAD_ERR_OK) {
                    throw new Exception('Seleziona un file valido da importare.');
                }

                $tmp = $_FILES['template_import_file']['tmp_name'];
                $origName = (string)($_FILES['template_import_file']['name'] ?? '');
                $ext = strtolower(pathinfo($origName, PATHINFO_EXTENSION));

                $enabled = 1;
                $overlay = 0;
                $mapJson = '';
                $bgRel = '';

                $uploadsRel = 'data/uploads';
                $uploadsAbs = __DIR__ . '/' . $uploadsRel;
                if (!is_dir($uploadsAbs)) @mkdir($uploadsAbs, 0775, true);

                if ($ext === 'zip') {
                    if (!class_exists('ZipArchive')) {
                        throw new Exception('ZipArchive non disponibile sul server.');
                    }
                    $zip = new ZipArchive();
                    if ($zip->open($tmp) !== true) {
                        throw new Exception('Impossibile aprire il file ZIP.');
                    }

                    // Read manifest (optional)
                    $manifestJson = $zip->getFromName('template.json');
                    if ($manifestJson !== false) {
                        $man = json_decode((string)$manifestJson, true);
                        if (is_array($man)) {
                            $enabled = (int)($man['enabled'] ?? $enabled);
                            $overlay = (int)($man['overlay_header'] ?? $overlay);
                        }
                    }

                    // Read map
                    $mapFromZip = $zip->getFromName('template_map.json');
                    if ($mapFromZip === false) {
                        // Backward compat: map embedded inside template.json
                        if ($manifestJson !== false) {
                            $man2 = json_decode((string)$manifestJson, true);
                            if (is_array($man2) && !empty($man2['map_json'])) {
                                $mapFromZip = (string)$man2['map_json'];
                            }
                        }
                    }
                    if ($mapFromZip === false) {
                        throw new Exception('Il pacchetto non contiene template_map.json.');
                    }
                    $mapJson = trim((string)$mapFromZip);

                    // Validate JSON
                    $tmpDecoded = json_decode($mapJson, true);
                    if (!is_array($tmpDecoded)) {
                        throw new Exception('La mappa JSON importata non è valida.');
                    }

                    // Background (optional)
                    $bgName = null;
                    for ($i = 0; $i < $zip->numFiles; $i++) {
                        $stat = $zip->statIndex($i);
                        if (!is_array($stat)) continue;
                        $n = (string)($stat['name'] ?? '');
                        if (preg_match('/^background\.(png|jpg|jpeg)$/i', $n)) { $bgName = $n; break; }
                    }

                    if ($bgName) {
                        $raw = $zip->getFromName($bgName);
                        if ($raw !== false) {
                            $bgExt = strtolower(pathinfo($bgName, PATHINFO_EXTENSION));
                            if ($bgExt === 'jpeg') $bgExt = 'jpg';
                            $fname = 'template_' . $safeKey . '_clinic_' . (int)$clinicId . '_import_' . date('Ymd_His') . '.' . $bgExt;
                            $absOut = $uploadsAbs . '/' . $fname;
                            file_put_contents($absOut, $raw);
                            if (file_exists($absOut)) {
                                $bgRel = $uploadsRel . '/' . $fname;
                            }
                        }
                    }

                    $zip->close();
                } elseif ($ext === 'json') {
                    $raw = file_get_contents($tmp);
                    if ($raw === false) throw new Exception('Impossibile leggere il file JSON.');
                    $decoded = json_decode($raw, true);
                    if (!is_array($decoded)) throw new Exception('JSON non valido.');

                    // Optional settings (supported by the JSON export fallback)
                    if (array_key_exists('enabled', $decoded)) {
                        $enabled = !empty($decoded['enabled']) ? 1 : 0;
                    }
                    if (array_key_exists('overlay_header', $decoded)) {
                        $overlay = !empty($decoded['overlay_header']) ? 1 : 0;
                    }

                    // Accept either a full map object or a wrapper with map_json
                    if (isset($decoded['items']) && is_array($decoded['items'])) {
                        $mapJson = json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                    } elseif (!empty($decoded['map_json'])) {
                        $mapJson = (string)$decoded['map_json'];
                    } else {
                        throw new Exception('Il file JSON non contiene una mappa valida.');
                    }

                    // Optional background image (supported by the JSON export fallback)
                    if (!empty($decoded['background_base64'])) {
                        $b64 = (string)$decoded['background_base64'];
                        $bin = base64_decode($b64, true);
                        if ($bin === false) {
                            throw new Exception('Background_base64 non valido nel JSON importato.');
                        }
                        $bgExt = strtolower((string)($decoded['background_ext'] ?? 'png'));
                        if ($bgExt === 'jpeg') $bgExt = 'jpg';
                        if (!in_array($bgExt, ['png','jpg','jpeg'], true)) $bgExt = 'png';
                        $fname = 'template_' . $safeKey . '_clinic_' . (int)$clinicId . '_import_' . date('Ymd_His') . '.' . $bgExt;
                        $absOut = $uploadsAbs . '/' . $fname;
                        file_put_contents($absOut, $bin);
                        if (file_exists($absOut)) {
                            $bgRel = $uploadsRel . '/' . $fname;
                        }
                    }
                } else {
                    throw new Exception('Formato import non supportato. Carica un .zip esportato da VetRoom oppure un .json.');
                }

                // Save config
                $payload = [
                    'enabled' => $enabled ? 1 : 0,
                    'overlay_header' => $overlay ? 1 : 0,
                    'map_json' => $mapJson,
                ];
                if ($bgRel !== '') $payload['bg_path'] = $bgRel;
                vr_visit_template_upsert($db, $clinicId, $sheetKey, $payload);

                $tpl_success = 'Template importato con successo.';
            } catch (Exception $e) {
                $tpl_error = 'Errore import template: ' . $e->getMessage();
            }
        }

        // --- Upload background (PNG/JPG/PDF) ---
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && ( $_POST['form_action'] ?? '' ) === 'upload_visit_template') {
            try {
                $sheet = $validate_sheet((string)($_POST['template_sheet_key'] ?? ($_POST['template_kind'] ?? 'clinica')));
                $sheetKey = $sheet['key'];
                $safeKey = vr_visit_sheet_key_to_filename($sheetKey);

                if (!isset($_FILES['template_file']) || $_FILES['template_file']['error'] !== UPLOAD_ERR_OK) {
                    throw new Exception('Seleziona un file valido.');
                }

                $tmp = $_FILES['template_file']['tmp_name'];
                $origName = $_FILES['template_file']['name'] ?? '';
                $ext = strtolower(pathinfo($origName, PATHINFO_EXTENSION));
                if (!in_array($ext, ['png','jpg','jpeg','pdf'], true)) {
                    throw new Exception('Formato non supportato. Carica PNG, JPG o PDF.');
                }

                $uploadDirAbs = __DIR__ . '/data/uploads';
                if (!is_dir($uploadDirAbs)) @mkdir($uploadDirAbs, 0775, true);

                $bgRel = '';
                $bgAbs = '';

                if ($ext === 'pdf') {
                    $pdfAbs = $uploadDirAbs . '/template_' . $safeKey . '_clinic_' . $clinicId . '.pdf';
                    if (!move_uploaded_file($tmp, $pdfAbs)) {
                        throw new Exception('Impossibile salvare il PDF.');
                    }

                    $pngAbs = $uploadDirAbs . '/template_' . $safeKey . '_clinic_' . $clinicId . '.png';
                    $converted = false;

                    if (class_exists('Imagick')) {
                        try {
                            $im = new Imagick();
                            $im->setResolution(150, 150);
                            $im->readImage($pdfAbs . '[0]');
                            $im->setImageFormat('png');
                            $im->writeImage($pngAbs);
                            $im->clear();
                            $im->destroy();
                            $converted = file_exists($pngAbs);
                        } catch (Exception $ie) {
                            $converted = false;
                        }
                    }

                    if (!$converted && function_exists('shell_exec')) {
                        $cmd = 'pdftoppm -png -f 1 -l 1 -r 150 ' . escapeshellarg($pdfAbs) . ' ' . escapeshellarg($pngAbs . '_tmp');
                        @shell_exec($cmd);
                        $candidate = $pngAbs . '_tmp-1.png';
                        if (file_exists($candidate)) {
                            @rename($candidate, $pngAbs);
                            foreach (glob($pngAbs . '_tmp-*.png') as $g) { @unlink($g); }
                            $converted = file_exists($pngAbs);
                        }
                    }

                    if (!$converted) {
                        throw new Exception('PDF caricato, ma conversione in immagine non disponibile sul server. Carica un PNG/JPG oppure abilita Imagick/pdftoppm.');
                    }

                    $bgAbs = $pngAbs;
                    $bgRel = 'data/uploads/' . basename($pngAbs);
                } else {
                    $finalExt = ($ext === 'jpeg') ? 'jpg' : $ext;
                    $dstAbs = $uploadDirAbs . '/template_' . $safeKey . '_clinic_' . $clinicId . '.' . $finalExt;
                    if (!move_uploaded_file($tmp, $dstAbs)) {
                        throw new Exception('Impossibile salvare il template immagine.');
                    }
                    $bgAbs = $dstAbs;
                    $bgRel = 'data/uploads/' . basename($dstAbs);
                }

                if ($bgRel === '' || !file_exists($bgAbs)) {
                    throw new Exception('Template non salvato correttamente.');
                }

                vr_visit_template_upsert($db, $clinicId, $sheetKey, [
                    'enabled' => 1,
                    'bg_path' => $bgRel,
                    'overlay_header' => 0,
                ]);

                $tpl_success = 'Sfondo caricato e attivato per la scheda selezionata.';
            } catch (Exception $e) {
                $tpl_error = 'Errore template: ' . $e->getMessage();
            }
        }

        // --- Save template mapping/settings ---
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && ( $_POST['form_action'] ?? '' ) === 'save_visit_template') {
            try {
                $sheet = $validate_sheet((string)($_POST['template_sheet_key'] ?? ($_POST['template_kind'] ?? 'clinica')));
                $sheetKey = $sheet['key'];

                $enabled = !empty($_POST['template_enabled']) ? 1 : 0;
                $overlay = !empty($_POST['template_overlay_header']) ? 1 : 0;
                $mapJson = trim((string)($_POST['template_map_json'] ?? ''));

                if ($mapJson !== '') {
                    $tmp = json_decode($mapJson, true);
                    if (!is_array($tmp)) {
                        throw new Exception('La mappa JSON non è valida.');
                    }
                }

                vr_visit_template_upsert($db, $clinicId, $sheetKey, [
                    'enabled' => $enabled,
                    'overlay_header' => $overlay,
                    'map_json' => ($mapJson === '' ? null : $mapJson),
                ]);

                $tpl_success = 'Impostazioni template salvate.';
            } catch (Exception $e) {
                $tpl_error = 'Errore salvataggio template: ' . $e->getMessage();
            }
        }

        // --- Reset template ---
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && ( $_POST['form_action'] ?? '' ) === 'reset_visit_template') {
            try {
                $sheet = $validate_sheet((string)($_POST['template_sheet_key'] ?? ($_POST['template_kind'] ?? 'clinica')));
                $sheetKey = $sheet['key'];

                vr_visit_template_upsert($db, $clinicId, $sheetKey, [
                    'enabled' => 0,
                    'overlay_header' => 0,
                    'bg_path' => null,
                    'map_json' => null,
                ]);

                $tpl_success = 'Template ripristinato (si useranno i preset di default).';
            } catch (Exception $e) {
                $tpl_error = 'Errore ripristino template: ' . $e->getMessage();
            }
        }

        // --- Generate a clean template + mapping (beta) ---
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && ( $_POST['form_action'] ?? '' ) === 'generate_visit_template') {
            try {
                $sheet = $validate_sheet((string)($_POST['template_sheet_key'] ?? ($_POST['template_kind'] ?? 'clinica')));
                $sheetKey = $sheet['key'];
                $safeKey = vr_visit_sheet_key_to_filename($sheetKey);

                $layout = strtolower(trim((string)($_POST['template_layout'] ?? 'standard')));
                if ($layout === '') $layout = 'standard';

                $accent = strtoupper(trim((string)($_POST['template_accent_color'] ?? '#0E7490')));
                if ($accent !== '' && $accent[0] !== '#') $accent = '#' . $accent;
                if (!preg_match('/^#[0-9A-F]{6}$/', $accent)) $accent = '#0E7490';

                $showLabels = true;
                if (array_key_exists('template_show_labels', $_POST)) {
                    $showLabels = !empty($_POST['template_show_labels']);
                }

                require_once __DIR__ . '/lib/template_generator.php';
                // The generator can always create a mapping.
                // Creating the background PNG needs GD; on hostings without GD we still generate the map
                // so the feature remains usable (the vet can upload a background manually).

                $genKind = ($sheetKey === 'oftalmo') ? 'oftalmo' : ((strpos($sheetKey, 'form:') === 0 || $sheetKey === 'custom') ? 'custom' : 'clinica');
                $safeLayout = preg_replace('/[^a-z0-9_]+/i', '_', $layout);
                $title = (string)($sheet['title'] ?? '');
                $mapArr = vr_tplgen_generate_clean_map($genKind, $layout, $accent, $title, ['show_labels' => $showLabels]);
                $mapJson = json_encode($mapArr, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                if ($mapJson === false) $mapJson = null;

                if (!vr_tplgen_has_gd()) {
                    vr_visit_template_upsert($db, $clinicId, $sheetKey, [
                        'enabled' => 1,
                        'overlay_header' => 1,
                        'map_json' => $mapJson,
                    ]);
                    $tpl_success = 'Mappa generata automaticamente. Nota: lo sfondo PNG non è stato creato perché l\'estensione PHP GD non è disponibile. Puoi caricare uno sfondo manualmente.';
                } else {
                    $uploadsRel = 'data/uploads';
                    $uploadsAbs = __DIR__ . '/' . $uploadsRel;
                    if (!is_dir($uploadsAbs)) {
                        @mkdir($uploadsAbs, 0775, true);
                    }

                    $fname = 'tpl_clean_' . $safeKey . '_' . $safeLayout . '_clinic_' . (int)$clinicId . '_' . date('Ymd_His') . '.png';
                    $absOut = $uploadsAbs . '/' . $fname;
                    $relOut = $uploadsRel . '/' . $fname;

                    $ok = vr_tplgen_generate_clean_png($genKind, $layout, $accent, $absOut, $title);
                    if (!$ok) {
                        throw new Exception('Impossibile generare il PNG del template (verifica permessi cartella uploads).');
                    }

                    vr_visit_template_upsert($db, $clinicId, $sheetKey, [
                        'enabled' => 1,
                        'overlay_header' => 1,
                        'bg_path' => $relOut,
                        'map_json' => $mapJson,
                    ]);

                    $tpl_success = 'Template generato e mappa creata automaticamente. Ora puoi rifinire tutto con l\'editor visuale.';
                }
            } catch (Exception $e) {
                $tpl_error = 'Errore generazione template: ' . $e->getMessage();
            }
        }
        // Salvataggio impostazioni PDF (scheda visita)
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && ( $_POST['form_action'] ?? '' ) === 'save_pdf_settings') {
            try {
                $incoming = [
                    // Colors
                    'accent_color' => trim($_POST['pdf_accent_color'] ?? ''),
                    'border_color' => trim($_POST['pdf_border_color'] ?? ''),

                    // Line
                    'line_y_cm' => $_POST['pdf_line_y_cm'] ?? '',
                    'line_thickness_px' => $_POST['pdf_line_thickness_px'] ?? '',

                    // Logo
                    'logo_x_cm' => $_POST['pdf_logo_x_cm'] ?? '',
                    'logo_y_cm' => $_POST['pdf_logo_y_cm'] ?? '',
                    'logo_size_cm' => $_POST['pdf_logo_size_cm'] ?? '',

                    // Title + subtitle
                    'title_x_cm' => $_POST['pdf_title_x_cm'] ?? '',
                    'title_y_cm' => $_POST['pdf_title_y_cm'] ?? '',
                    'title_w_cm' => $_POST['pdf_title_w_cm'] ?? '',
                    'title_h_cm' => $_POST['pdf_title_h_cm'] ?? '',
                    'title_border' => !empty($_POST['pdf_title_border']) ? 1 : 0,
                    'title_border_px' => $_POST['pdf_title_border_px'] ?? '',
                    'title_align' => $_POST['pdf_title_align'] ?? '',
                    'title_font_size' => $_POST['pdf_title_font_size'] ?? '',
                    'title_font_style' => $_POST['pdf_title_font_style'] ?? '',
                    'subtitle_font_size' => $_POST['pdf_subtitle_font_size'] ?? '',
                    'subtitle_font_style' => $_POST['pdf_subtitle_font_style'] ?? '',
                    'subtitle_offset_cm' => $_POST['pdf_subtitle_offset_cm'] ?? '',

                    // Vet
                    'vet_x_cm' => $_POST['pdf_vet_x_cm'] ?? '',
                    'vet_y_cm' => $_POST['pdf_vet_y_cm'] ?? '',
                    'vet_w_cm' => $_POST['pdf_vet_w_cm'] ?? '',
                    'vet_h_cm' => $_POST['pdf_vet_h_cm'] ?? '',
                    'vet_border' => !empty($_POST['pdf_vet_border']) ? 1 : 0,
                    'vet_border_px' => $_POST['pdf_vet_border_px'] ?? '',
                    'vet_align' => $_POST['pdf_vet_align'] ?? '',
                    'vet_font_size' => $_POST['pdf_vet_font_size'] ?? '',
                    'vet_font_style' => $_POST['pdf_vet_font_style'] ?? '',

                    // Animal
                    'animal_x_cm' => $_POST['pdf_animal_x_cm'] ?? '',
                    'animal_y_cm' => $_POST['pdf_animal_y_cm'] ?? '',
                    'animal_w_cm' => $_POST['pdf_animal_w_cm'] ?? '',
                    'animal_h_cm' => $_POST['pdf_animal_h_cm'] ?? '',
                    'animal_border' => !empty($_POST['pdf_animal_border']) ? 1 : 0,
                    'animal_border_px' => $_POST['pdf_animal_border_px'] ?? '',
                    'animal_align' => $_POST['pdf_animal_align'] ?? '',
                    'animal_font_size' => $_POST['pdf_animal_font_size'] ?? '',
                    'animal_font_style' => $_POST['pdf_animal_font_style'] ?? '',

                    // Visit
                    'visit_x_cm' => $_POST['pdf_visit_x_cm'] ?? '',
                    'visit_y_cm' => $_POST['pdf_visit_y_cm'] ?? '',
                    'visit_w_cm' => $_POST['pdf_visit_w_cm'] ?? '',
                    'visit_h_cm' => $_POST['pdf_visit_h_cm'] ?? '',
                    'visit_border' => !empty($_POST['pdf_visit_border']) ? 1 : 0,
                    'visit_border_px' => $_POST['pdf_visit_border_px'] ?? '',
                    'visit_align' => $_POST['pdf_visit_align'] ?? '',
                    'visit_font_size' => $_POST['pdf_visit_font_size'] ?? '',
                    'visit_font_style' => $_POST['pdf_visit_font_style'] ?? '',

                    // Owner
                    'owner_x_cm' => $_POST['pdf_owner_x_cm'] ?? '',
                    'owner_y_cm' => $_POST['pdf_owner_y_cm'] ?? '',
                    'owner_w_cm' => $_POST['pdf_owner_w_cm'] ?? '',
                    'owner_h_cm' => $_POST['pdf_owner_h_cm'] ?? '',
                    'owner_border' => !empty($_POST['pdf_owner_border']) ? 1 : 0,
                    'owner_border_px' => $_POST['pdf_owner_border_px'] ?? '',
                    'owner_align' => $_POST['pdf_owner_align'] ?? '',
                    'owner_font_size' => $_POST['pdf_owner_font_size'] ?? '',
                    'owner_font_style' => $_POST['pdf_owner_font_style'] ?? '',

                    // Global
                    'line_height_pt' => $_POST['pdf_line_height_pt'] ?? '',
                    'blank_lines_after_patient' => $_POST['pdf_blank_lines_after_patient'] ?? '',
                    'gap_after_form_pt' => $_POST['pdf_gap_after_form_pt'] ?? '',
                ];

                $spec = vr_pdf_settings_normalize($incoming);
                $json = json_encode($spec, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                if ($json === false) throw new Exception('Impossibile serializzare le impostazioni PDF.');

                $ensure_vet_settings_row();
                $now = vr_now_iso();
                $stmtUp = $db->prepare("UPDATE vet_settings SET pdf_settings = ?, updated_at = ? WHERE clinic_id = ?");
                $stmtUp->execute([$json, $now, $clinicId]);
                $pdf_success = 'Impostazioni PDF salvate.';
            } catch (Exception $e) {
                $pdf_error = 'Errore salvataggio impostazioni PDF: ' . $e->getMessage();
            }
        }

        // Ripristina default PDF (scheda visita)
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && ( $_POST['form_action'] ?? '' ) === 'reset_pdf_settings') {
            try {
                $ensure_vet_settings_row();
                $now = vr_now_iso();
                $stmtUp = $db->prepare("UPDATE vet_settings SET pdf_settings = NULL, updated_at = ? WHERE clinic_id = ?");
                $stmtUp->execute([$now, $clinicId]);
                $pdf_success = 'Impostazioni PDF ripristinate ai valori di default.';
            } catch (Exception $e) {
                $pdf_error = 'Errore ripristino impostazioni PDF: ' . $e->getMessage();
            }
        }

        // Recupera intestazione corrente
        $currentHeader = vr_get_vet_header($clinicId);
        $pdfSpec = vr_pdf_settings_from_json($currentHeader['pdf_settings'] ?? null);

        /* ==========================
         * BACKUP: Export / Import
         * ========================== */

        // --- EXPORT full backup (ZIP) ---
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && ( $_POST['form_action'] ?? '' ) === 'export_backup_full') {
            try {
                if (!$isAdmin) {
                    throw new Exception('Solo un utente Admin può esportare un backup completo.');
                }

                // Build a manifest with a few useful stats.
                $counts = [];
                try {
                    $counts['owners'] = (int)$db->query("SELECT COUNT(1) FROM owners WHERE clinic_id = " . (int)$clinicId)->fetchColumn();
                    $counts['pets'] = (int)$db->query("SELECT COUNT(1) FROM pets WHERE clinic_id = " . (int)$clinicId)->fetchColumn();
                    $counts['visits'] = (int)$db->query("SELECT COUNT(1) FROM visits WHERE clinic_id = " . (int)$clinicId)->fetchColumn();
                    $counts['appointments'] = (int)$db->query("SELECT COUNT(1) FROM appointments WHERE clinic_id = " . (int)$clinicId)->fetchColumn();
                    $counts['documents'] = (int)$db->query("SELECT COUNT(1) FROM documents WHERE clinic_id = " . (int)$clinicId)->fetchColumn();
                    $counts['visit_forms'] = (int)$db->query("SELECT COUNT(1) FROM visit_forms WHERE clinic_id = " . (int)$clinicId)->fetchColumn();
                } catch (Throwable $t) {
                    // ignore stats errors
                }

                $manifest = [
                    'vetroom_backup_version' => 1,
                    'exported_at' => vr_now_iso(),
                    'clinic_id' => (int)$clinicId,
                    'clinic_name' => (string)($currentHeader['header_name'] ?? ''),
                    'php_version' => PHP_VERSION,
                    'db_file' => 'db/vetroom2.sqlite',
                    'uploads_dir' => 'uploads/',
                    'private_uploads_dir' => 'private_uploads/',
                    'counts' => $counts,
                ];

                // Create a stable copy of the DB first.
                // We close the PDO to avoid copying a locked/dirty file on some hosts.
                // Close PDO before copying the SQLite file (avoids locks on some hosts/OS).
                vetroom_db_close();
                $db = null;
                $dbCopy = vr_backup_export_db_copy(VETROOM_DB_PATH);
                // Re-open DB for the rest of the request
                $db = vetroom_db();

                $uploadsAbs = __DIR__ . '/data/uploads';
                $privateAbs = __DIR__ . '/data/private_uploads';
                $zipPath = vr_backup_export_zip($dbCopy, $uploadsAbs, $manifest, $privateAbs);
                @unlink($dbCopy);

                $dlName = 'vetroom_backup_' . date('Ymd_His') . '.zip';
                vr_backup_stream_download_and_delete($zipPath, $dlName, 'application/zip');
            } catch (Exception $e) {
                $backup_error = 'Errore export backup: ' . $e->getMessage();
            }
        }

        // --- EXPORT database only (SQLite) ---
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && ( $_POST['form_action'] ?? '' ) === 'export_backup_db') {
            try {
                if (!$isAdmin) {
                    throw new Exception('Solo un utente Admin può esportare il database.');
                }
                vetroom_db_close();
                $db = null;
                $dbCopy = vr_backup_export_db_copy(VETROOM_DB_PATH);
                $db = vetroom_db();
                $dlName = 'vetroom_db_' . date('Ymd_His') . '.sqlite';
                vr_backup_stream_download_and_delete($dbCopy, $dlName, 'application/octet-stream');
            } catch (Exception $e) {
                $backup_error = 'Errore export database: ' . $e->getMessage();
            }
        }

        // --- IMPORT full backup (ZIP) or DB-only (SQLite) ---
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && ( $_POST['form_action'] ?? '' ) === 'import_backup') {
            try {
                if (!$isAdmin) {
                    throw new Exception('Solo un utente Admin può importare un backup.');
                }

                $confirm = trim((string)($_POST['backup_confirm_phrase'] ?? ''));
                if ($confirm !== 'voglio ripristinare il backup') {
                    throw new Exception('Conferma non valida. Devi scrivere esattamente: "voglio ripristinare il backup"');
                }

                if (!isset($_FILES['backup_file']) || ($_FILES['backup_file']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
                    throw new Exception('Seleziona un file di backup valido.');
                }

                $tmp = (string)$_FILES['backup_file']['tmp_name'];
                $name = (string)($_FILES['backup_file']['name'] ?? '');
                $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
                $keepCreds = !isset($_POST['keep_credentials']) || (string)$_POST['keep_credentials'] === '1';

                $backupDir = __DIR__ . '/data/backups';
                if (!is_dir($backupDir)) {
                    @mkdir($backupDir, 0775, true);
                }
                // Best-effort protection from direct web access (Apache).
                $bht = $backupDir . '/.htaccess';
                if (!file_exists($bht)) {
                    @file_put_contents($bht, "Require all denied\n");
                }
                $ts = date('Ymd_His');

                // Safety copy of current DB
                if (is_file(VETROOM_DB_PATH)) {
                    @copy(VETROOM_DB_PATH, $backupDir . '/vetroom2_pre_restore_' . $ts . '.sqlite');
                }

                if ($ext === 'zip') {
                    // Safety rename uploads folder
                    $uploadsAbs = __DIR__ . '/data/uploads';
                    $privateAbs = __DIR__ . '/data/private_uploads';
                    if (is_dir($uploadsAbs)) {
                        // If folder has content, move it aside
                        $backupUploads = $backupDir . '/uploads_pre_restore_' . $ts;
                        @rename($uploadsAbs, $backupUploads);
                    }
                    if (!is_dir($uploadsAbs)) {
                        @mkdir($uploadsAbs, 0775, true);
                    }
                    if (is_dir($privateAbs)) {
                        $backupPrivate = $backupDir . '/private_uploads_pre_restore_' . $ts;
                        @rename($privateAbs, $backupPrivate);
                    }
                    if (!is_dir($privateAbs)) {
                        @mkdir($privateAbs, 0775, true);
                    }

                    vetroom_db_close();
                    $db = null;
                    $manifest = vr_backup_import_zip($tmp, VETROOM_DB_PATH, $uploadsAbs, $privateAbs, $keepCreds);
                    // Force new DB connection
                    $db = vetroom_db();

                    // Log out after restore to avoid stale sessions pointing to old users.
                    $_SESSION['vetroom_notice'] = 'Backup ripristinato correttamente. Effettua di nuovo il login.';
                    vetroom_logout();
                    header('Location: index.php');
                    exit;
                } elseif ($ext === 'sqlite' || $ext === 'db') {
                    vetroom_db_close();
                    $db = null;
                    $credSnap = $keepCreds ? vr_backup_capture_credentials_from_db(VETROOM_DB_PATH) : ['users'=>[], 'platform_users'=>[]];
                    vr_backup_import_sqlite($tmp, VETROOM_DB_PATH);
                    if ($keepCreds && (!empty($credSnap['users']) || !empty($credSnap['platform_users']))) {
                        vr_backup_restore_credentials_to_db(VETROOM_DB_PATH, $credSnap);
                    }
                    $db = vetroom_db();
                    $_SESSION['vetroom_notice'] = 'Database ripristinato correttamente. Effettua di nuovo il login.';
                    vetroom_logout();
                    header('Location: index.php');
                    exit;
                } else {
                    throw new Exception('Formato non supportato. Carica un file .zip (backup completo) oppure .sqlite (solo database).');
                }
            } catch (Exception $e) {
                $backup_error = 'Errore import backup: ' . $e->getMessage();
            }
        }

        // Template settings are now stored per "scheda visita" (visit_templates table).
        // We will load the current sheet config later, after fetching available schede.
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && ( $_POST['form_action'] ?? '' ) === 'save_settings') {
            try {
                $db->beginTransaction();
                $stmtDel = $db->prepare("DELETE FROM availabilities WHERE clinic_id = ?");
                $stmtDel->execute([$clinicId]);

                $stmtIns = $db->prepare("
                    INSERT INTO availabilities (clinic_id, weekday, start_time, end_time, start_time2, end_time2, slot_minutes, created_at, updated_at)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
                ");
                $now = vr_now_iso();

                for ($w = 1; $w <= 7; $w++) {
                    $start = trim($_POST['start_' . $w] ?? '');
                    $end = trim($_POST['end_' . $w] ?? '');
                    $start2 = trim($_POST['start2_' . $w] ?? '');
                    $end2 = trim($_POST['end2_' . $w] ?? '');
                    $slot = (int)($_POST['slot_' . $w] ?? 30);
                    // Allow only-one-range configs: if morning is empty but afternoon is set, shift it.
                    if ($start === '' && $end === '' && $start2 !== '' && $end2 !== '') {
                        $start = $start2; $end = $end2;
                        $start2 = ''; $end2 = '';
                    }

                    // Normalise incomplete afternoon range
                    if (!( $start2 !== '' && $end2 !== '' )) {
                        $start2 = ''; $end2 = '';
                    }

                    if ($start !== '' && $end !== '') {
                        if ($slot <= 0) $slot = 30;
                        $stmtIns->execute([$clinicId, $w, $start, $end, $start2, $end2, $slot, $now, $now]);
                    }
                }

                $db->commit();
                $settings_success = 'Impostazioni agenda salvate correttamente.';
            } catch (Exception $e) {
                if ($db->inTransaction()) {
                    $db->rollBack();
                }
                $settings_error = 'Errore durante il salvataggio: ' . $e->getMessage();
            }
        }

        $stmtA = $db->prepare("SELECT weekday, start_time, end_time, start_time2, end_time2, slot_minutes FROM availabilities WHERE clinic_id = ?");
        $stmtA->execute([$clinicId]);
        $avail = [];
        while ($row = $stmtA->fetch(PDO::FETCH_ASSOC)) {
            $avail[(int)$row['weekday']] = $row;
        }

        $days = [
            1 => 'Lunedì',
            2 => 'Martedì',
            3 => 'Mercoledì',
            4 => 'Giovedì',
            5 => 'Venerdì',
            6 => 'Sabato',
            7 => 'Domenica',
        ];

        // Settings sub-categories (tabs) to avoid a single long "all-in-one" page.
        $settings_tab = strtolower(trim((string)($_GET['tab'] ?? ($_POST['settings_tab'] ?? 'template'))));
        $allowedTabs = ['studio', 'template', 'visite', 'agenda', 'backup'];
        if (!in_array($settings_tab, $allowedTabs, true)) $settings_tab = 'template';

        $showStudio = ($settings_tab === 'studio');
        $showPdf = false; // PDF settings moved into the visual template editor
        $showTemplate = ($settings_tab === 'template');
        $showVisite = ($settings_tab === 'visite');
        $showAgenda = ($settings_tab === 'agenda');
        $showBackup = ($settings_tab === 'backup');

        // --- Custom visit forms (builder) ---
        $vf_error = '';
        $vf_success = '';
        $editFormId = (int)($_GET['form_id'] ?? ($_POST['form_id'] ?? 0));
        $editForm = null;

        if ($_SERVER['REQUEST_METHOD'] === 'POST' && ( $_POST['form_action'] ?? '' ) === 'save_visit_form') {
            try {
                $name = trim((string)($_POST['visit_form_name'] ?? ''));
                if ($name === '') throw new Exception('Inserisci un nome per il modulo.');

                $slug = vr_slugify_key($name);
                if ($slug === '') $slug = 'modulo_' . time();

                // Build fields
                $secA = $_POST['field_section'] ?? [];
                $lblA = $_POST['field_label'] ?? [];
                $typA = $_POST['field_type'] ?? [];
                $keyA = $_POST['field_key'] ?? [];
                $optA = $_POST['field_options'] ?? [];
                $reqA = $_POST['field_required'] ?? [];
                $fields = [];
                $n = max(count($lblA), count($typA), count($keyA));
                for ($i=0; $i<$n; $i++) {
                    $label = trim((string)($lblA[$i] ?? ''));
                    if ($label === '') continue;
                    $section = trim((string)($secA[$i] ?? ''));
                    $type = strtolower(trim((string)($typA[$i] ?? 'text')));
                    if (!in_array($type, ['text','textarea','number','select','checkbox'], true)) $type = 'text';
                    $key = trim((string)($keyA[$i] ?? ''));
                    if ($key === '') $key = vr_slugify_key($label);
                    if ($key === '') $key = 'campo_' . ($i+1);
                    $optsRaw = trim((string)($optA[$i] ?? ''));
                    $options = [];
                    if ($type === 'select' && $optsRaw !== '') {
                        foreach (preg_split('/\s*,\s*/', $optsRaw) as $o) {
                            $o = trim($o);
                            if ($o !== '') $options[] = $o;
                        }
                    }
                    $required = !empty($reqA[$i]) ? 1 : 0;
                    $fields[] = [
                        'key' => $key,
                        'label' => $label,
                        'section' => $section,
                        'type' => $type,
                        'required' => $required,
                        'options' => $options,
                    ];
                }

                $def = [
                    'version' => 1,
                    'fields' => $fields,
                ];
                $defJson = json_encode($def, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                $now = vr_now_iso();

                if ($editFormId > 0) {
                    $stmt = $db->prepare("UPDATE visit_forms SET name = ?, slug = ?, definition_json = ?, updated_at = ? WHERE id = ? AND clinic_id = ?");
                    $stmt->execute([$name, $slug, $defJson, $now, $editFormId, $clinicId]);
                    $vf_success = 'Modulo aggiornato.';
                } else {
                    $stmt = $db->prepare("INSERT INTO visit_forms (clinic_id, name, slug, definition_json, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?)");
                    $stmt->execute([$clinicId, $name, $slug, $defJson, $now, $now]);
                    $editFormId = (int)$db->lastInsertId();
                    $vf_success = 'Modulo creato.';
                }
            } catch (Exception $e) {
                $vf_error = $e->getMessage();
            }
        }

        if ($_SERVER['REQUEST_METHOD'] === 'POST' && ( $_POST['form_action'] ?? '' ) === 'delete_visit_form') {
            try {
                $did = (int)($_POST['delete_form_id'] ?? 0);
                if ($did <= 0) throw new Exception('Modulo non valido.');
                // Prevent deletion if used by visits
                $chk = $db->prepare("SELECT COUNT(1) FROM visits WHERE clinic_id = ? AND visit_form_id = ?");
                $chk->execute([$clinicId, $did]);
                $cnt = (int)$chk->fetchColumn();
                if ($cnt > 0) throw new Exception('Impossibile eliminare: esistono visite collegate a questo modulo.');
                $stmt = $db->prepare("DELETE FROM visit_forms WHERE id = ? AND clinic_id = ?");
                $stmt->execute([$did, $clinicId]);
                // Remove associated per-sheet template (if any)
                try {
                    $vkey = 'form:' . $did;
                    $stmtT = $db->prepare("DELETE FROM visit_templates WHERE clinic_id = ? AND visit_key = ?");
                    $stmtT->execute([$clinicId, $vkey]);
                } catch (Throwable $te) {
                    // ignore
                }
                if ($editFormId === $did) $editFormId = 0;
                $vf_success = 'Modulo eliminato.';
            } catch (Exception $e) {
                $vf_error = $e->getMessage();
            }
        }

        // Fetch all custom visit forms for this clinic
        $stmtVForms = $db->prepare("SELECT * FROM visit_forms WHERE clinic_id = ? ORDER BY name COLLATE NOCASE");
        $stmtVForms->execute([$clinicId]);
        $visitForms = $stmtVForms->fetchAll(PDO::FETCH_ASSOC);

        // ----------------------------
        // VISIT SHEETS (schede visita)
        // ----------------------------
        // Built-in + custom modules, used by:
        // - Template editor selector
        // - Template generator selector
        // - Field library in the visual editor
        require_once __DIR__ . '/lib/visit_templates_repo.php';
        require_once __DIR__ . '/lib/visit_template_maps.php';

        $visitSheets = [];
        $visitSheets[] = ['key' => 'clinica', 'label' => 'Visita clinica / generale', 'kind' => 'clinica', 'fields' => []];
        $visitSheets[] = ['key' => 'oftalmo', 'label' => 'Visita oftalmologica', 'kind' => 'oftalmo', 'fields' => []];
        foreach ($visitForms as $vfRow) {
            $vid = (int)($vfRow['id'] ?? 0);
            if ($vid <= 0) continue;
            $vkey = 'form:' . $vid;
            $vlabel = (string)($vfRow['name'] ?? ('Modulo #' . $vid));

            $fields = [];
            if (!empty($vfRow['definition_json'])) {
                $def = json_decode((string)$vfRow['definition_json'], true);
                if (is_array($def) && !empty($def['fields']) && is_array($def['fields'])) {
                    foreach ($def['fields'] as $f) {
                        if (!is_array($f)) continue;
                        $fkey = trim((string)($f['key'] ?? ''));
                        $flab = trim((string)($f['label'] ?? ''));
                        if ($fkey === '' || $flab === '') continue;
                        $fields[] = [
                            'key' => $fkey,
                            'label' => $flab,
                            'section' => (string)($f['section'] ?? ''),
                            'type' => (string)($f['type'] ?? 'text'),
                        ];
                    }
                }
            }

            $visitSheets[] = ['key' => $vkey, 'label' => $vlabel, 'kind' => 'custom', 'fields' => $fields];
        }

        // Selected sheet (for the template tab)
        $selectedSheetRaw = (string)($_GET['sheet'] ?? ($_POST['template_sheet_key'] ?? 'clinica'));
        try {
            $selectedSheet = $validate_sheet($selectedSheetRaw);
        } catch (Exception $e) {
            $selectedSheet = $validate_sheet('clinica');
        }
        $selectedSheetKey = (string)($selectedSheet['key'] ?? 'clinica');

        // Load template config for selected sheet
        $selCfg = vr_visit_template_get($db, $clinicId, $selectedSheetKey) ?: [];
        // Backward compat fallback (legacy columns) for built-in sheets if needed
        if (empty($selCfg) && ($selectedSheetKey === 'clinica' || $selectedSheetKey === 'oftalmo')) {
            $isOft = ($selectedSheetKey === 'oftalmo');
            $selCfg = [
                'enabled' => !empty($currentHeader[$isOft ? 'visit_template_oftalmo_enabled' : 'visit_template_clinica_enabled']) ? 1 : 0,
                'bg_path' => (string)($currentHeader[$isOft ? 'visit_template_oftalmo_bg' : 'visit_template_clinica_bg'] ?? ''),
                'map_json' => (string)($currentHeader[$isOft ? 'visit_template_oftalmo_map' : 'visit_template_clinica_map'] ?? ''),
                'overlay_header' => !empty($currentHeader[$isOft ? 'visit_template_oftalmo_overlay_header' : 'visit_template_clinica_overlay_header']) ? 1 : 0,
            ];
        }

        $tpl_selected_enabled = !empty($selCfg['enabled']);
        $tpl_selected_bg = trim((string)($selCfg['bg_path'] ?? ''));
        $tpl_selected_overlay = !empty($selCfg['overlay_header']);
        $tpl_selected_map = trim((string)($selCfg['map_json'] ?? ''));
        if ($tpl_selected_map === '') {
            if ($selectedSheetKey === 'oftalmo') {
                $tpl_selected_map = vr_visit_template_default_map_json('oftalmologica');
            } elseif ($selectedSheetKey === 'clinica') {
                $tpl_selected_map = vr_visit_template_default_map_json('clinica');
            } else {
                $tpl_selected_map = vr_visit_template_default_map_custom_form_json((string)($selectedSheet['title'] ?? 'Visita personalizzata'));
            }
        }

        // Preview background (fallback to built-in templates)
        // NOTE: /app/data is intentionally not web-accessible. Any custom background stored under
        // data/uploads must be served through an authenticated endpoint; otherwise the <img>
        // won't load (and the visual editor looks "crashed" because the stage height collapses).
        $tpl_default_preview = ($selectedSheetKey === 'oftalmo')
            ? 'public/templates/scheda_visita_oftalmologica_vetroom.png'
            : 'public/templates/scheda_visita_clinica_vetroom.png';

        $tpl_selected_preview = $tpl_default_preview;
        if ($tpl_selected_bg !== '') {
            // Use a cache-busting version parameter bound to the template row updated_at when available.
            $v = (string)($selCfg['updated_at'] ?? '');
            if ($v === '') $v = (string)time();
            $tpl_selected_preview = 'asset.php?type=template_bg&sheet=' . rawurlencode($selectedSheetKey) . '&v=' . rawurlencode($v);
        }

        // JS registry for the editor
        $jsSheets = [];
        foreach ($visitSheets as $s) {
            $k = (string)($s['key'] ?? '');
            if ($k === '') continue;
            $kind = (string)($s['kind'] ?? 'clinica');
            $label = (string)($s['label'] ?? $k);
            if ($k === 'clinica') {
                $preset = vr_visit_template_default_map_json('clinica');
            } elseif ($k === 'oftalmo') {
                $preset = vr_visit_template_default_map_json('oftalmologica');
            } else {
                $preset = vr_visit_template_default_map_custom_form_json($label);
            }
            $jsSheets[$k] = [
                'key' => $k,
                'label' => $label,
                'kind' => ($k === 'oftalmo' ? 'oftalmo' : ($k === 'clinica' ? 'clinica' : 'custom')),
                'presetJson' => $preset,
                'fields' => $s['fields'] ?? [],
            ];
        }

        if ($editFormId > 0) {
            $stmtEF = $db->prepare("SELECT * FROM visit_forms WHERE id = ? AND clinic_id = ?");
            $stmtEF->execute([$editFormId, $clinicId]);
            $editForm = $stmtEF->fetch(PDO::FETCH_ASSOC) ?: null;
        }

        vr_layout_start('Opzioni - ' . VETROOM_APP_NAME, $user, 'settings');
        ?>
        <h1 class="vr-page-title">Opzioni studio</h1>
        <p class="vr-page-subtitle">Configura giorni e orari di visita usati dall'agenda.</p>

        <div class="vr-settings-nav">
            <a class="vr-settings-nav-item<?php echo $showStudio ? ' active' : ''; ?>" href="index.php?page=settings&tab=studio">Intestazione &amp; logo</a>
            <a class="vr-settings-nav-item<?php echo $showTemplate ? ' active' : ''; ?>" href="index.php?page=settings&tab=template">Template visite</a>
            <a class="vr-settings-nav-item<?php echo $showVisite ? ' active' : ''; ?>" href="index.php?page=settings&tab=visite">Moduli visite</a>
            <a class="vr-settings-nav-item<?php echo $showAgenda ? ' active' : ''; ?>" href="index.php?page=settings&tab=agenda">Agenda</a>
            <a class="vr-settings-nav-item<?php echo $showBackup ? ' active' : ''; ?>" href="index.php?page=settings&tab=backup">Backup</a>
        </div>

        <div class="vr-settings-section" data-settings-section="studio" style="<?php echo $showStudio ? '' : 'display:none;'; ?>">
        <div class="vr-card">
            <div class="vr-card-header">Intestazione del professionista</div>
            <?php if ($header_error): ?><div class="vr-alert vr-alert-error"><?php echo vr_h($header_error); ?></div><?php endif; ?>
            <?php if ($header_success): ?><div class="vr-alert vr-alert-success"><?php echo vr_h($header_success); ?></div><?php endif; ?>
            <form method="post">
                <input type="hidden" name="settings_tab" value="<?php echo vr_h($settings_tab); ?>">
                <input type="hidden" name="form_action" value="save_header">
                <?php echo vr_staff_csrf_field(); ?>
                <div class="vr-form-row"><label class="vr-label" for="header_name">Nome</label>
                    <input class="vr-input" type="text" id="header_name" name="header_name" value="<?php echo vr_h($currentHeader['header_name'] ?? ''); ?>" required></div>
                <div class="vr-form-row"><label class="vr-label" for="header_title">Titolo</label>
                    <input class="vr-input" type="text" id="header_title" name="header_title" value="<?php echo vr_h($currentHeader['header_title'] ?? ''); ?>"></div>
                <div class="vr-form-row"><label class="vr-label" for="header_piva">P. IVA</label>
                    <input class="vr-input" type="text" id="header_piva" name="header_piva" value="<?php echo vr_h($currentHeader['header_piva'] ?? ''); ?>"></div>
                <div class="vr-form-row"><label class="vr-label" for="header_albo">Iscrizione Albo</label>
                    <input class="vr-input" type="text" id="header_albo" name="header_albo" value="<?php echo vr_h($currentHeader['header_albo'] ?? ''); ?>"></div>
                <div class="vr-form-row"><label class="vr-label" for="header_phone">Telefono</label>
                    <input class="vr-input" type="text" id="header_phone" name="header_phone" value="<?php echo vr_h($currentHeader['header_phone'] ?? ''); ?>"></div>
                <div class="vr-form-row"><label class="vr-label" for="header_email">Email</label>
                    <input class="vr-input" type="email" id="header_email" name="header_email" value="<?php echo vr_h($currentHeader['header_email'] ?? ''); ?>"></div>
                <div class="vr-form-row"><label class="vr-label" for="header_address">Indirizzo</label>
                    <input class="vr-input" type="text" id="header_address" name="header_address" value="<?php echo vr_h($currentHeader['header_address'] ?? ''); ?>"></div>
                <button type="submit" class="vr-button" style="margin-top:8px;">Salva intestazione</button>
            </form>
        </div>

        <div class="vr-card">
            <div class="vr-card-header">Logo dello studio</div>
            <?php if ($logo_error): ?><div class="vr-alert vr-alert-error"><?php echo vr_h($logo_error); ?></div><?php endif; ?>
            <?php if ($logo_success): ?><div class="vr-alert vr-alert-success"><?php echo vr_h($logo_success); ?></div><?php endif; ?>
            <div class="vr-form-row">
                <?php if (!empty($currentHeader['logo_path'])): ?>
                    <img src="asset.php?type=logo&amp;v=<?php echo vr_h((string)($currentHeader['updated_at'] ?? '')); ?>" alt="Logo" style="max-height:60px;max-width:200px;border:1px solid #eee;padding:4px;background:#fff;">
                <?php else: ?>
                    <div style="font-size:12px;color:#666;">Nessun logo caricato (l'area in intestazione PDF resterà bianca).</div>
                <?php endif; ?>
            </div>
            <form method="post" enctype="multipart/form-data">
                <input type="hidden" name="settings_tab" value="<?php echo vr_h($settings_tab); ?>">
                <input type="hidden" name="form_action" value="upload_logo">
                <?php echo vr_staff_csrf_field(); ?>
                <div class="vr-form-row"><label class="vr-label" for="logo_file">Seleziona immagine</label>
                    <input class="vr-input" type="file" id="logo_file" name="logo_file" accept="image/*"></div>
                <button type="submit" class="vr-button" style="margin-top:8px;">Carica logo</button>
            </form>
        </div>

        <div class="vr-card">
            <div class="vr-card-header">Timbro</div>
            <?php if (!empty($stamp_error)): ?><div class="vr-alert vr-alert-error"><?php echo vr_h($stamp_error); ?></div><?php endif; ?>
            <?php if (!empty($stamp_success)): ?><div class="vr-alert vr-alert-success"><?php echo vr_h($stamp_success); ?></div><?php endif; ?>
            <div class="vr-form-row">
                <?php if (!empty($currentHeader['stamp_path'])): ?>
                    <img src="asset.php?type=stamp&amp;v=<?php echo vr_h((string)($currentHeader['updated_at'] ?? '')); ?>" alt="Timbro" style="max-height:90px;max-width:260px;border:1px solid #eee;padding:4px;background:#fff;">
                <?php else: ?>
                    <div style="font-size:12px;color:#666;">Nessun timbro caricato. Puoi inserirlo nella scheda visita dall'editor visuale (Elementi grafici → Timbro).</div>
                <?php endif; ?>
            </div>
            <form method="post" enctype="multipart/form-data">
                <input type="hidden" name="settings_tab" value="<?php echo vr_h($settings_tab); ?>">
                <input type="hidden" name="form_action" value="upload_stamp">
                <?php echo vr_staff_csrf_field(); ?>
                <div class="vr-form-row"><label class="vr-label" for="stamp_file">Seleziona immagine</label>
                    <input class="vr-input" type="file" id="stamp_file" name="stamp_file" accept="image/*"></div>
                <button type="submit" class="vr-button" style="margin-top:8px;">Carica timbro</button>
            </form>
        </div>
        </div>

        <div class="vr-settings-section" data-settings-section="template" style="<?php echo $showTemplate ? '' : 'display:none;'; ?>">
        <div class="vr-card">
            <div class="vr-card-header">Template schede visita</div>

            <?php if ($tpl_error): ?><div class="vr-alert vr-alert-error"><?php echo vr_h($tpl_error); ?></div><?php endif; ?>
            <?php if ($tpl_success): ?><div class="vr-alert vr-alert-success"><?php echo vr_h($tpl_success); ?></div><?php endif; ?>

            <p style="font-size:12px;margin-top:0;color:#666;">
                Seleziona una <strong>scheda visita</strong> e personalizza il PDF con uno <strong>sfondo</strong> (PNG/JPG; PDF solo se il server può convertirlo) e con l'<strong>editor visuale</strong>.
                Non serve scrivere codice: trascina i campi e salva.
            </p>

            <form method="get" style="display:flex;gap:12px;flex-wrap:wrap;align-items:end;margin:12px 0 14px;">
                <input type="hidden" name="page" value="settings">
                <input type="hidden" name="tab" value="template">
                <div class="vr-form-row" style="min-width:280px;">
                    <label class="vr-label" for="vr_tpl_sheet_select">Scheda visita</label>
                    <select class="vr-input" id="vr_tpl_sheet_select" name="sheet" onchange="this.form.submit()">
                        <?php foreach ($visitSheets as $s): ?>
                            <option value="<?php echo vr_h($s['key']); ?>" <?php echo ($selectedSheetKey === $s['key']) ? 'selected' : ''; ?>><?php echo vr_h($s['label']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div style="font-size:12px;color:#666;max-width:520px;line-height:1.3;">
                    Ogni scheda ha il suo ecosistema: <strong>sfondo</strong>, <strong>mappa campi</strong>, elementi grafici e preset.
                </div>
            </form>

            <div style="display:flex;gap:16px;flex-wrap:wrap;align-items:flex-start;">
                <div style="min-width:260px;">
                    <div style="font-size:12px;color:#666;margin-bottom:6px;">Anteprima</div>
                    <img id="tpl_preview_current" src="<?php echo vr_h($tpl_selected_preview); ?>" alt="Template visita" style="max-width:320px;width:100%;border:1px solid #eee;background:#fff;" />

                    <div style="margin-top:8px;font-size:12px;color:#666;">Clicca sull'immagine per ottenere coordinate (x_mm, y_mm):</div>
                    <input class="vr-input" id="tpl_coords_current" type="text" readonly value="" placeholder="es. 42.50, 80.10" style="max-width:220px;" />
                </div>

                <div style="flex:1;min-width:320px;">
                    <form method="post" enctype="multipart/form-data" style="margin-bottom:10px;">
                        <input type="hidden" name="settings_tab" value="<?php echo vr_h($settings_tab); ?>">
                        <input type="hidden" name="form_action" value="upload_visit_template">
                        <?php echo vr_staff_csrf_field(); ?>
                        <input type="hidden" name="template_sheet_key" value="<?php echo vr_h($selectedSheetKey); ?>">
                        <div class="vr-form-row"><label class="vr-label" for="tpl_file_current">Carica sfondo (PNG/JPG; PDF*)</label>
                            <input class="vr-input" type="file" id="tpl_file_current" name="template_file" accept=".png,.jpg,.jpeg,.pdf"></div>
                        <button type="submit" class="vr-button">Carica sfondo</button>
                        <div style="margin-top:6px;font-size:12px;color:#666;">
                            * Il PDF viene convertito in immagine solo se il server lo permette (Imagick o pdftoppm).
                        </div>
                    </form>

                    <form method="post">
                        <input type="hidden" name="settings_tab" value="<?php echo vr_h($settings_tab); ?>">
                        <input type="hidden" name="form_action" value="save_visit_template">
                        <?php echo vr_staff_csrf_field(); ?>
                        <input type="hidden" name="template_sheet_key" value="<?php echo vr_h($selectedSheetKey); ?>">

                        <div class="vr-form-row" style="display:flex;align-items:center;gap:10px;">
                            <input type="checkbox" id="tpl_enabled_current" name="template_enabled" value="1" <?php echo $tpl_selected_enabled ? 'checked' : ''; ?> />
                            <label class="vr-label" for="tpl_enabled_current" style="margin:0;">Usa questo template per questa scheda visita</label>
                        </div>

                        <div class="vr-form-row" style="display:flex;align-items:center;gap:10px;">
                            <input type="checkbox" id="tpl_overlay_current" name="template_overlay_header" value="1" <?php echo $tpl_selected_overlay ? 'checked' : ''; ?> />
                            <label class="vr-label" for="tpl_overlay_current" style="margin:0;">Sovrascrivi intestazione (logo + dati studio) sul template</label>
                        </div>

                        <div class="vr-form-row">
                            <label class="vr-label">Allineamento campi</label>
                            <div style="display:flex;gap:10px;flex-wrap:wrap;align-items:center;">
                                <button type="button" class="vr-button" data-open-template-editor="<?php echo vr_h($selectedSheetKey); ?>">Apri editor visuale</button>
                                <button type="button" class="vr-button" style="background:#444;" data-reset-template-map="<?php echo vr_h($selectedSheetKey); ?>">Ripristina preset</button>
                                <button type="submit" class="vr-button">Salva template</button>
                            </div>
                            <div style="margin-top:6px;font-size:12px;color:#666;">Nessun codice: trascina i campi nell'editor visuale, poi clicca <strong>Salva template</strong> per applicare le modifiche.</div>

                            <details style="margin-top:10px;">
                                <summary style="font-weight:600;cursor:pointer;">Modalità avanzata (JSON)</summary>
                                <textarea class="vr-input" id="tpl_map_current" name="template_map_json" data-vr-no-uppercase rows="10" style="font-family:ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, 'Liberation Mono', 'Courier New', monospace; font-size:12px;"><?php echo vr_h($tpl_selected_map); ?></textarea>
                            </details>
                        </div>
                    </form>

                    <div style="display:flex;gap:10px;flex-wrap:wrap;align-items:center;margin-top:8px;">
                        <form method="post" style="margin:0;">
                            <input type="hidden" name="settings_tab" value="<?php echo vr_h($settings_tab); ?>">
                            <input type="hidden" name="form_action" value="reset_visit_template">
                            <?php echo vr_staff_csrf_field(); ?>
                            <input type="hidden" name="template_sheet_key" value="<?php echo vr_h($selectedSheetKey); ?>">
                            <button type="submit" class="vr-button" style="background:#444;">Reset template</button>
                        </form>
                        <form method="post" style="margin:0;">
                            <input type="hidden" name="settings_tab" value="<?php echo vr_h($settings_tab); ?>">
                            <input type="hidden" name="form_action" value="export_visit_template">
                            <?php echo vr_staff_csrf_field(); ?>
                            <input type="hidden" name="template_sheet_key" value="<?php echo vr_h($selectedSheetKey); ?>">
                            <button type="submit" class="vr-button vr-button-secondary">Esporta template</button>
                        </form>
                    </div>

                    <form method="post" enctype="multipart/form-data" style="margin-top:10px;">
                        <input type="hidden" name="settings_tab" value="<?php echo vr_h($settings_tab); ?>">
                        <input type="hidden" name="form_action" value="import_visit_template">
                        <?php echo vr_staff_csrf_field(); ?>
                        <input type="hidden" name="template_sheet_key" value="<?php echo vr_h($selectedSheetKey); ?>">
                        <div class="vr-form-row"><label class="vr-label" for="tpl_import_file">Importa template (ZIP/JSON)</label>
                            <input class="vr-input" type="file" id="tpl_import_file" name="template_import_file" accept=".zip,.json"></div>
                        <button type="submit" class="vr-button vr-button-secondary">Importa</button>
                        <div style="margin-top:6px;font-size:12px;color:#666;">Puoi esportare un template da un altro studio/PC e importarlo qui. Lo sfondo (se presente) viene copiato negli upload.</div>
                    </form>

                    <details style="margin-top:14px;">
                        <summary style="font-weight:600;cursor:pointer;">Generatore template (beta)</summary>
                        <div style="margin-top:10px;">
                            <div style="font-size:12px;color:#666;margin-bottom:8px;">
                                Crea un template <strong>pulito</strong> (A4) con <strong>colore</strong> e <strong>layout</strong> già pronti e una <strong>mappa campi automatica</strong>.<br>
                                Poi puoi rifinire tutto con l'editor visuale.
                            </div>

                            <form method="post" style="display:flex;gap:12px;flex-wrap:wrap;align-items:end;">
                                <input type="hidden" name="settings_tab" value="<?php echo vr_h($settings_tab); ?>">
                                <input type="hidden" name="form_action" value="generate_visit_template">
                                <?php echo vr_staff_csrf_field(); ?>

                                <div class="vr-form-row" style="min-width:240px;">
                                    <label class="vr-label" for="tpl_gen_sheet">Scheda visita</label>
                                    <select class="vr-input" id="tpl_gen_sheet" name="template_sheet_key">
                                        <?php foreach ($visitSheets as $s): ?>
                                            <option value="<?php echo vr_h($s['key']); ?>" <?php echo ($selectedSheetKey === $s['key']) ? 'selected' : ''; ?>><?php echo vr_h($s['label']); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>

                                <div class="vr-form-row" style="min-width:180px;">
                                    <label class="vr-label" for="tpl_gen_layout">Layout</label>
                                    <select class="vr-input" id="tpl_gen_layout" name="template_layout">
                                        <option value="standard">Standard (una colonna)</option>
                                        <option value="two_col">Compatto (due colonne)</option>
                                    </select>
                                </div>

                                <div class="vr-form-row" style="min-width:160px;">
                                    <label class="vr-label" for="tpl_gen_color">Colore</label>
                                    <input class="vr-input" id="tpl_gen_color" type="color" name="template_accent_color" value="#0E7490" style="height:40px;padding:4px;">
                                </div>

                                <div class="vr-form-row" style="min-width:220px;">
                                    <label class="vr-label" style="margin:0;">Nomi campi</label>
                                    <label style="display:flex;gap:8px;align-items:center;font-size:13px;">
                                        <input type="checkbox" name="template_show_labels" value="1" checked>
                                        Mostra nomi campi nel PDF
                                    </label>
                                </div>

                                <div class="vr-form-row" style="min-width:220px;">
                                    <button type="submit" class="vr-button">Genera template</button>
                                    <div style="margin-top:6px;font-size:12px;color:#666;">
                                        Nota: la generazione richiede l'estensione PHP <strong>GD</strong>.
                                    </div>
                                </div>
                            </form>
                        </div>
                    </details>
                </div>
            </div>

            <!-- Visual template editor modal (single instance reused for clinica/oftalmo) -->
            <div class="vr-modal" id="vr_tpl_editor_modal" style="display:none;" aria-hidden="true">
                <div class="vr-modal-content" role="dialog" aria-modal="true" aria-labelledby="vr_tpl_editor_title">
                    <div class="vr-modal-header">
                        <div class="vr-modal-title" id="vr_tpl_editor_title">Editor template</div>
                        <button type="button" class="vr-button vr-button-secondary" id="vr_tpl_editor_close">Chiudi</button>
                    </div>
                    <div class="vr-modal-body">
                        <div class="vr-editor-canvas-wrap">
                            <div class="vr-editor-stage" id="vr_tpl_editor_stage">
                                <img id="vr_tpl_editor_bg" src="" alt="Sfondo template" />
                                <div class="vr-editor-overlay" id="vr_tpl_editor_overlay"></div>
                            </div>
                        </div>
                        <div class="vr-editor-panel">
                            <div class="vr-editor-panel-row">
                                <div class="vr-editor-panel-title">Campi</div>
                                <div class="vr-editor-panel-sub" id="vr_tpl_editor_kind_hint"></div>
                            </div>

                            <div class="vr-editor-panel-row" style="gap:10px; align-items:center;">
                                <label style="font-size:12px;color:#555;">Zoom</label>
                                <input type="range" id="vr_tpl_editor_zoom" min="60" max="160" value="100" />
                                <span style="font-size:12px;color:#555;" id="vr_tpl_editor_zoom_val">100%</span>
                            </div>

                            <div style="display:flex;gap:10px;flex-wrap:wrap;align-items:center;margin-bottom:8px;">
                                <button type="button" class="vr-button vr-button-secondary" id="vr_tpl_editor_fit_btn">Adatta pagina</button>
                                <button type="button" class="vr-button vr-button-secondary" id="vr_tpl_editor_top_btn">Vai in alto</button>
                            </div>

                            <div class="vr-editor-field-list" id="vr_tpl_editor_list"></div>

                            <div class="vr-editor-props" id="vr_tpl_editor_props">
                                <div style="font-size:12px;color:#666;">Seleziona un campo per modificarne posizione e stile.</div>
                            </div>

                            <div class="vr-editor-actions">
                                <div class="vr-editor-add-row">
                                    <select class="vr-input" id="vr_tpl_editor_add_select"></select>
                                    <button type="button" class="vr-button vr-button-secondary" id="vr_tpl_editor_add_btn">Aggiungi</button>
                                </div>
                                <div style="display:flex;gap:10px;flex-wrap:wrap;">
                                    <button type="button" class="vr-button vr-button-secondary" id="vr_tpl_editor_reset_btn">Ripristina preset</button>
                                    <button type="button" class="vr-button" id="vr_tpl_editor_save_btn">Salva mappa</button>
                                </div>
                                <div style="font-size:12px;color:#666;">Suggerimento: trascina i riquadri sul foglio. Usa il pallino in basso a destra per ridimensionare.</div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <script>
                (function(){
                    // Preset JSON
                    var presetClinicaJson = <?php echo json_encode(vr_visit_template_default_map_json('clinica')); ?>;
                    var presetOftalmoJson = <?php echo json_encode(vr_visit_template_default_map_json('oftalmologica')); ?>;

                    // Human labels (shown in the visual editor and used as default printable field names).
                    // These must reflect what the vet sees in the forms, not the programmer keys.
                    var humanLabelsClinica = {
                        pet_name: 'Nome animale',
                        owner_full: 'Proprietario',
                        pet_species: 'Specie',
                        owner_phone: 'Telefono',
                        pet_breed: 'Razza',
                        owner_email: 'Email',
                        pet_birth_date: 'Data di nascita',
                        owner_address: 'Indirizzo',
                        pet_sex: 'Sesso',
                        visit_date: 'Data visita',
                        pet_microchip: 'Microchip',
                        visit_reason: 'Motivo visita',

                        anamnesis: 'Anamnesi',

                        obj_feci: 'Feci',
                        obj_temperatura: 'Temperatura',
                        obj_fc: 'Frequenza cardiaca',
                        obj_sensorio: 'Stato sensorio',
                        obj_respirazione: 'Respirazione',
                        obj_mucose: 'Mucose',
                        obj_riempimento_capillare: 'Riempimento capillare',
                        obj_idratazione: 'Stato di idratazione',
                        obj_linfonodi: 'Note linfonodi',
                        obj_polso_femorale: 'Polso femorale',
                        obj_polso_tarsale: 'Polso tarsale',
                        obj_auscultazione_cardiaca: 'Auscultazione cardiaca',
                        obj_apparato_respiratorio: 'Apparato respiratorio',
                        obj_palpazione_addome: 'Palpazione addome',

                        diagnosis: 'Diagnosi',
                        therapy: 'Terapia / Prescrizioni',
                        notes: 'Note / Raccomandazioni'
                    };

                    var humanLabelsOftalmo = {
                        pet_name: 'Nome animale',
                        owner_full: 'Proprietario',
                        pet_species: 'Specie',
                        owner_phone: 'Telefono',
                        pet_breed: 'Razza',
                        owner_email: 'Email',
                        pet_birth_date: 'Data di nascita',
                        owner_address: 'Indirizzo',
                        pet_sex: 'Sesso',
                        visit_date: 'Data visita',
                        pet_microchip: 'Microchip',
                        visit_reason: 'Motivo visita',

                        anamnesis: 'Anamnesi',
                        vision: 'Visione',
                        cornea: 'Cornea',
                        pupillare: 'Riflesso pupillare',
                        camera: 'Camera anteriore',
                        schirmer: 'Schirmer',
                        cristallino: 'Cristallino',
                        tonometria: 'Tonometria',
                        fondo: 'Fondo',
                        fluor: 'Fluoresceina',
                        palpebre: 'Palpebre',
                        congiuntiva: 'Congiuntiva',
                        pio_od: 'PIO OD',
                        pio_os: 'PIO OS',
                        note_exam: 'Note esame',

                        // --- Campi form oftalmo completi (DX / SX) ---
                        // Riflessi
                        minaccia_dx: 'Riflesso minaccia DX',
                        minaccia_sx: 'Riflesso minaccia SX',
                        palpebrale_dx: 'Riflesso palpebrale DX',
                        palpebrale_sx: 'Riflesso palpebrale SX',
                        pupillare_dx: 'Riflesso pupillare DX',
                        pupillare_sx: 'Riflesso pupillare SX',
                        dazzle_dx: 'Dazzle DX',
                        dazzle_sx: 'Dazzle SX',
                        corneale_dx: 'Riflesso corneale DX',
                        corneale_sx: 'Riflesso corneale SX',

                        // Annessi
                        orbita_dx: 'Orbita DX',
                        orbita_sx: 'Orbita SX',
                        palpebre_dx: 'Palpebre DX',
                        palpebre_sx: 'Palpebre SX',
                        terza_dx: '3a palpebra DX',
                        terza_sx: '3a palpebra SX',
                        lacrimale_dx: 'Sistema lacrimale DX',
                        lacrimale_sx: 'Sistema lacrimale SX',
                        congiuntiva_dx: 'Congiuntiva DX',
                        congiuntiva_sx: 'Congiuntiva SX',

                        // Occhio
                        fluor_dx: 'Fluoresceina DX',
                        fluor_sx: 'Fluoresceina SX',
                        schirmer_dx: 'Schirmer DX',
                        schirmer_sx: 'Schirmer SX',
                        cornea_dx: 'Cornea DX',
                        cornea_sx: 'Cornea SX',
                        camera_dx: 'Camera anteriore DX',
                        camera_sx: 'Camera anteriore SX',
                        iride_dx: 'Iride e pupilla DX',
                        iride_sx: 'Iride e pupilla SX',
                        cristallino_dx: 'Cristallino DX',
                        cristallino_sx: 'Cristallino SX',
                        vitreo_dx: 'Vitreo DX',
                        vitreo_sx: 'Vitreo SX',
                        fondo_dx: 'Fondo DX',
                        fondo_sx: 'Fondo SX',
                        iop_dx: 'IOP DX (mm Hg)',
                        iop_sx: 'IOP SX (mm Hg)',

                        diagnosis: 'Diagnosi',
                        therapy: 'Terapia',
                        notes: 'Note'
                    };

                    // Registry of available "schede visita" (built-in + moduli personalizzati)
                    var vrSheets = <?php echo json_encode($jsSheets, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
                    var currentSheetKey = <?php echo json_encode($selectedSheetKey); ?>;

                    function vrGetSheet(key) {
                        key = (key || '').toString().trim();
                        if (key === 'oftalmologica') key = 'oftalmo';
                        if (vrSheets && Object.prototype.hasOwnProperty.call(vrSheets, key)) return vrSheets[key];
                        return null;
                    }

                    // Textarea where the mapping is persisted (advanced view)
                    var tMap = document.getElementById('tpl_map_current');
                    if (tMap) {
                        var sh0 = vrGetSheet(currentSheetKey);
                        tMap.dataset.defaultJson = (sh0 && sh0.presetJson) ? sh0.presetJson : presetClinicaJson;
                    }

                    // Small helper: click on preview image -> show coordinates
                    function attachClickHelper(imgId, outId) {
                        var img = document.getElementById(imgId);
                        var out = document.getElementById(outId);
                        if (!img || !out) return;
                        img.addEventListener('click', function(ev){
                            var r = img.getBoundingClientRect();
                            var xPx = ev.clientX - r.left;
                            var yPx = ev.clientY - r.top;
                            var xMm = (xPx / r.width) * 210.0;
                            var yMm = (yPx / r.height) * 297.0;
                            out.value = xMm.toFixed(2) + ', ' + yMm.toFixed(2);
                        });
                    }
                    attachClickHelper('tpl_preview_current', 'tpl_coords_current');

                    // --- Visual editor implementation ---
                    var modal = document.getElementById('vr_tpl_editor_modal');
                    var modalClose = document.getElementById('vr_tpl_editor_close');
                    var modalTitle = document.getElementById('vr_tpl_editor_title');
                    var kindHint = document.getElementById('vr_tpl_editor_kind_hint');
                    var stage = document.getElementById('vr_tpl_editor_stage');
                    var bgImg = document.getElementById('vr_tpl_editor_bg');
                    var overlay = document.getElementById('vr_tpl_editor_overlay');
                    var listEl = document.getElementById('vr_tpl_editor_list');
                    var propsEl = document.getElementById('vr_tpl_editor_props');
                    var addSel = document.getElementById('vr_tpl_editor_add_select');
                    var addBtn = document.getElementById('vr_tpl_editor_add_btn');
                    var resetBtn = document.getElementById('vr_tpl_editor_reset_btn');
                    var saveBtn = document.getElementById('vr_tpl_editor_save_btn');
                    var zoom = document.getElementById('vr_tpl_editor_zoom');
                    var zoomVal = document.getElementById('vr_tpl_editor_zoom_val');
                    var fitBtn = document.getElementById('vr_tpl_editor_fit_btn');
                    var topBtn = document.getElementById('vr_tpl_editor_top_btn');
                    var canvasWrap = modal ? modal.querySelector('.vr-editor-canvas-wrap') : null;

                    if (!modal || !stage || !bgImg || !overlay) return;

                    var state = {
                        kind: null,
                        textarea: null,
                        previewImg: null,
                        presetJson: null,
                        map: null,
                        selectedIndices: [],
                        primaryIndex: -1,
                        library: [],
                    };

                    function safeParseJson(txt, fallback) {
                        try {
                            var obj = JSON.parse(txt);
                            if (obj && typeof obj === 'object') return obj;
                        } catch(e) {}
                        try {
                            var obj2 = JSON.parse(fallback);
                            if (obj2 && typeof obj2 === 'object') return obj2;
                        } catch(e2) {}
                        return { meta:{name:'Template'}, default_font:{size:9,style:''}, items:[] };
                    }

                    // The visual editor expects a stable mapping structure.
                    // In older installs / after import, the JSON might be missing
                    // some keys (meta/default_font/items). We normalise here so the
                    // editor never crashes and always has a predictable shape.
                    function ensureMapDefaults(map) {
                        if (!map || typeof map !== 'object') {
                            map = { meta:{name:'Template'}, default_font:{size:9,style:''}, items:[] };
                        }
                        if (!map.meta || typeof map.meta !== 'object') map.meta = {};
                        if (!('name' in map.meta) || String(map.meta.name || '').trim() === '') {
                            map.meta.name = 'Template';
                        }
                        if (!map.default_font || typeof map.default_font !== 'object') map.default_font = {};
                        if (!('size' in map.default_font) || isNaN(parseFloat(map.default_font.size))) {
                            map.default_font.size = 9;
                        }
                        if (!('style' in map.default_font)) map.default_font.style = '';
                        if (!Array.isArray(map.items)) map.items = [];
                        return map;
                    }

                    function itemDisplayName(it) {
                        if (!it) return '';
                        var ui = (it.ui_label && String(it.ui_label).trim() !== '') ? String(it.ui_label).trim() : '';
                        var lbl = (it.label && String(it.label).trim() !== '') ? String(it.label).trim() : '';
                        var key = (it.key && String(it.key).trim() !== '') ? String(it.key).trim() : '';
                        return ui || lbl || key || 'campo';
                    }

                    function clamp(v, min, max) {
                        if (isNaN(v)) return min;
                        return Math.max(min, Math.min(max, v));
                    }

                    function toPctX(mm) { return (mm / 210.0) * 100.0; }
                    function toPctY(mm) { return (mm / 297.0) * 100.0; }
                    function fromPctX(pct) { return (pct / 100.0) * 210.0; }
                    function fromPctY(pct) { return (pct / 100.0) * 297.0; }

                    function ensureItemDefaults(it) {
                        if (!('x_mm' in it)) it.x_mm = 10;
                        if (!('y_mm' in it)) it.y_mm = 10;
                        if (!('w_mm' in it)) it.w_mm = 40;
                        if (!('h_mm' in it)) it.h_mm = 0; // 0 => single-line
                        // Graphic elements (logo/line/signature) need a non-zero height
                        // and some extra defaults so they work out-of-the-box in the visual editor.
                        var t = String(it.type || 'text').toLowerCase();
                        if (t === 'line') {
                            if (!('color_hex' in it) || !String(it.color_hex||'').trim()) it.color_hex = '#0F766E';
                            var th = parseFloat(it.h_mm) || 0;
                            if (th <= 0) it.h_mm = 0.8; // ~0.8mm thickness
                        }
                        if (t === 'image' || t === 'logo') {
                            if (!('image_kind' in it) || !String(it.image_kind||'').trim()) it.image_kind = 'logo';
                            var ih = parseFloat(it.h_mm) || 0;
                            if (ih <= 0) it.h_mm = 18;
                        }
                        if (t === 'signature') {
                            if (!('color_hex' in it) || !String(it.color_hex||'').trim()) it.color_hex = '#111827';
                            if (!('line_thickness_mm' in it) || isNaN(parseFloat(it.line_thickness_mm))) it.line_thickness_mm = 0.3;
                            var sh = parseFloat(it.h_mm) || 0;
                            if (sh <= 0) it.h_mm = 10;
                            if (!('show_label' in it)) it.show_label = 1;
                            if (!('label' in it) || !String(it.label||'').trim()) it.label = 'Firma del medico';
                            if (!('label_font_size' in it) || isNaN(parseFloat(it.label_font_size))) it.label_font_size = 9;
                            if (!('label_align' in it) || !String(it.label_align||'').trim()) it.label_align = 'R';
                        }
                        if (!('ui_label' in it) || String(it.ui_label || '').trim() === '') {
                            // Prefer the human label (what the vet sees in the form).
                            // 1) If the item comes from a structured array, "subkey" is already human.
                            if (String(it.subkey || '').trim() !== '') {
                                it.ui_label = String(it.subkey || '').trim();
                            } else {
                                // 2) Known keys -> Italian labels
                                var dict = state.humanDict || ((state.kind === 'oftalmo' || state.kind === 'oftalmologica') ? humanLabelsOftalmo : humanLabelsClinica);
                                var k = String(it.key || '').trim();
                                if (k && dict && dict[k]) {
                                    it.ui_label = String(dict[k]);
                                } else if (String(it.label || '').trim() !== '') {
                                    // 3) Legacy mappings might already have a printable label.
                                    it.ui_label = String(it.label || '').trim();
                                } else if (k) {
                                    // 4) Last resort: key (programmer-ish)
                                    it.ui_label = k;
                                }
                            }
                        }
                        if (!('align' in it)) it.align = 'L';
                        if (!('font_size' in it)) it.font_size = (state.map && state.map.default_font && state.map.default_font.size) ? state.map.default_font.size : 9;
                        if (!('font_style' in it)) it.font_style = (state.map && state.map.default_font && state.map.default_font.style) ? state.map.default_font.style : '';
                        return it;
                    }

                    function renderBox(it, idx) {
                        ensureItemDefaults(it);
                        var box = document.createElement('div');
                        box.className = 'vr-tpl-box' + (isSelected(idx) ? ' selected' : '');
                        var __t = String(it.type || 'text').toLowerCase();
                        box.dataset.type = __t;
                        if (__t && __t !== 'text') box.className += ' vr-tpl-box--' + __t;
                        box.dataset.index = String(idx);

                        var name = document.createElement('div');
                        name.className = 'vr-tpl-box-label';
                        name.textContent = itemDisplayName(it);
                        box.appendChild(name);

                        var res = document.createElement('div');
                        res.className = 'vr-tpl-box-resizer';
                        res.title = 'Ridimensiona';
                        box.appendChild(res);

                        function applyPos() {
                            var x = clamp(parseFloat(it.x_mm) || 0, 0, 210);
                            var y = clamp(parseFloat(it.y_mm) || 0, 0, 297);
                            var w = clamp(parseFloat(it.w_mm) || 10, 5, 210);
                            var h = parseFloat(it.h_mm) || 0;

                            // UI: show a minimum height even for single-line fields
                            var uiH = (h > 0) ? h : 5.0;

                            box.style.left = toPctX(x) + '%';
                            box.style.top = toPctY(y) + '%';
                            box.style.width = toPctX(w) + '%';
                            box.style.height = toPctY(uiH) + '%';
                        }
                        applyPos();

                        // Selection
                        box.addEventListener('pointerdown', function(ev){
                            if (ev.target === res) return; // resize handled separately
                            // Multi-selection gestures: when holding Ctrl/⌘ or Shift we only change selection
                            // (no dragging), to avoid accidental moves while toggling selection.
                            if (ev.metaKey || ev.ctrlKey || ev.shiftKey) {
                                handleSelect(idx, ev);
                                return;
                            }
                            handleSelect(idx, ev);
                            startDrag(ev, idx);
                        });
                        res.addEventListener('pointerdown', function(ev){
                            ev.stopPropagation();
                            setSelection([idx], idx);
                            startResize(ev, idx);
                        });

                        it.__applyPos = applyPos;
                        return box;
                    }

                    function renderList() {
                        listEl.innerHTML = '';
                        (state.map.items || []).forEach(function(it, idx){
                            var row = document.createElement('div');
                            row.className = 'vr-editor-field' + (isSelected(idx) ? ' selected' : '');
                            row.dataset.index = String(idx);
                            row.textContent = itemDisplayName(it);
                            row.addEventListener('click', function(ev){ handleSelect(idx, ev); });
                            listEl.appendChild(row);
                        });
                    }

                    function renderOverlay() {
                        overlay.innerHTML = '';
                        (state.map.items || []).forEach(function(it, idx){
                            overlay.appendChild(renderBox(it, idx));
                        });
                    }

                    function renderAddLibrary() {
                        addSel.innerHTML = '';
                        var opt0 = document.createElement('option');
                        opt0.value = '';
                        opt0.textContent = '— Seleziona campo da aggiungere —';
                        addSel.appendChild(opt0);
                        state.library.forEach(function(def, i){
                            var opt = document.createElement('option');
                            opt.value = String(i);
                            opt.textContent = def.label;
                            addSel.appendChild(opt);
                        });
                    }

                    function buildExtraLibrary(kind) {
                        // Extra entries available in the visual editor.
                        // 1) Graphic elements (for ALL templates): logo, lines, signature.
                        // 2) Extra OFTALMO form fields that might be missing from some presets.
                        kind = String(kind || '').toLowerCase();
                        var out = [];
                        var isOft = (kind === 'oftalmo' || kind === 'oftalmologica');

                        function push(group, key, uiLabel, source) {
                            out.push({
                                label: group + ' — ' + uiLabel,
                                item: {
                                    key: key,
                                    ui_label: uiLabel,
                                    source: source,
                                    x_mm: 10,
                                    y_mm: 10,
                                    w_mm: 60,
                                    h_mm: 0,
                                    align: 'L'
                                }
                            });
                        }

                        function pushGraphic(label, item) {
                            out.push({
                                label: 'Elementi grafici — ' + label,
                                item: item
                            });
                        }

                        // --- Graphic elements (available for every template kind) ---
                        pushGraphic('Logo studio', {
                            type: 'image',
                            key: 'graphic_logo',
                            ui_label: 'Logo studio',
                            image_kind: 'logo',
                            x_mm: 14,
                            y_mm: 10,
                            w_mm: 30,
                            h_mm: 18
                        });

                        pushGraphic('Intestazione veterinario', {
                            type: 'text',
                            key: 'graphic_vet_header',
                            ui_label: 'Intestazione veterinario',
                            source: 'computed:vet_header_lines',
                            x_mm: 45,
                            y_mm: 10,
                            w_mm: 150,
                            h_mm: 22,
                            font_size: 9,
                            line_height_pt: 11,
                            align: 'L',
                            show_label: 0,
                            border: 0
                        });

                        pushGraphic('Timbro', {
                            type: 'image',
                            key: 'graphic_stamp',
                            ui_label: 'Timbro',
                            image_kind: 'stamp',
                            x_mm: 160,
                            y_mm: 245,
                            w_mm: 35,
                            h_mm: 35
                        });

                        pushGraphic('Linea', {
                            type: 'line',
                            key: 'graphic_line',
                            ui_label: 'Linea',
                            color_hex: '#0F766E',
                            x_mm: 10,
                            y_mm: 40,
                            w_mm: 190,
                            h_mm: 0.8
                        });

                        pushGraphic('Firma (linea + testo)', {
                            type: 'signature',
                            key: 'graphic_signature',
                            ui_label: 'Firma del medico',
                            x_mm: 120,
                            y_mm: 270,
                            w_mm: 70,
                            h_mm: 10,
                            color_hex: '#111827',
                            line_thickness_mm: 0.3,
                            show_label: 1,
                            label: 'Firma del medico',
                            label_font_size: 9,
                            label_align: 'R'
                        });

                        // Custom module fields (visit_forms)
                        // Note: we keep the selector simple (no optgroup support here),
                        // so we don't insert "group header" pseudo-items.
                        if (kind === 'custom' && state.sheet && Array.isArray(state.sheet.fields) && state.sheet.fields.length) {

                            var visitName = (state.sheet && state.sheet.label) ? String(state.sheet.label) : 'Visita';

                            out.push({
                                label: visitName + ' — Tutti i campi (compatti)',
                                item: {
                                    key: 'custom_fields_compact',
                                    ui_label: 'Tutti i campi (compatti)',
                                    label: 'Dati visita',
                                    show_label: 1,
                                    label_mode: 'above',
                                    source: 'computed:custom_fields_compact',
                                    x_mm: 14.82,
                                    y_mm: 91.72,
                                    w_mm: 180.37,
                                    h_mm: 70,
                                    align: 'L',
                                    font_size: 9,
                                    line_height_pt: 11,
                                    max_lines: 999
                                }
                            });

                            state.sheet.fields.forEach(function(f){
                                if (!f) return;
                                var ck = String(f.key || '').trim();
                                if (!ck) return;
                                var lbl = String(f.label || '').trim();
                                if (!lbl) lbl = ck;
                                var sec = String(f.section || '').trim();
                                var entryLabel = sec ? (sec + ' — ' + lbl) : lbl;
                                out.push({
                                    label: visitName + ' — ' + entryLabel,
                                    item: {
                                        key: 'cf_' + ck,
                                        ui_label: lbl,
                                        label: lbl,
                                        show_label: 1,
                                        label_mode: 'inline',
                                        source: 'computed:custom_field',
                                        custom_key: ck,
                                        x_mm: 10,
                                        y_mm: 10,
                                        w_mm: 100,
                                        h_mm: 0,
                                        align: 'L',
                                        font_size: 10
                                    }
                                });
                            });
                        }

                        if (!isOft) return out;

                        // --- OFTALMO extra fields (DX / SX) ---
                        push('Riflessi', 'minaccia_dx', 'Riflesso minaccia DX', 'form.reflex.minaccia_dx');
                        push('Riflessi', 'minaccia_sx', 'Riflesso minaccia SX', 'form.reflex.minaccia_sx');
                        push('Riflessi', 'palpebrale_dx', 'Riflesso palpebrale DX', 'form.reflex.palpebrale_dx');
                        push('Riflessi', 'palpebrale_sx', 'Riflesso palpebrale SX', 'form.reflex.palpebrale_sx');
                        push('Riflessi', 'pupillare_dx', 'Riflesso pupillare DX', 'form.reflex.pupillare_dx');
                        push('Riflessi', 'pupillare_sx', 'Riflesso pupillare SX', 'form.reflex.pupillare_sx');
                        push('Riflessi', 'dazzle_dx', 'Dazzle DX', 'form.reflex.dazzle_dx');
                        push('Riflessi', 'dazzle_sx', 'Dazzle SX', 'form.reflex.dazzle_sx');
                        push('Riflessi', 'corneale_dx', 'Riflesso corneale DX', 'form.reflex.corneale_dx');
                        push('Riflessi', 'corneale_sx', 'Riflesso corneale SX', 'form.reflex.corneale_sx');

                        push('Annessi', 'orbita_dx', 'Orbita DX', 'form.annessi.orbita_dx');
                        push('Annessi', 'orbita_sx', 'Orbita SX', 'form.annessi.orbita_sx');
                        push('Annessi', 'palpebre_dx', 'Palpebre DX', 'form.annessi.palpebre_dx');
                        push('Annessi', 'palpebre_sx', 'Palpebre SX', 'form.annessi.palpebre_sx');
                        push('Annessi', 'terza_dx', '3a palpebra DX', 'form.annessi.terza_dx');
                        push('Annessi', 'terza_sx', '3a palpebra SX', 'form.annessi.terza_sx');
                        push('Annessi', 'lacrimale_dx', 'Sistema lacrimale DX', 'form.annessi.lacrimale_dx');
                        push('Annessi', 'lacrimale_sx', 'Sistema lacrimale SX', 'form.annessi.lacrimale_sx');
                        push('Annessi', 'congiuntiva_dx', 'Congiuntiva DX', 'form.annessi.congiuntiva_dx');
                        push('Annessi', 'congiuntiva_sx', 'Congiuntiva SX', 'form.annessi.congiuntiva_sx');

                        push('Occhio', 'fluor_dx', 'Fluoresceina DX', 'form.occhio.fluor_dx');
                        push('Occhio', 'fluor_sx', 'Fluoresceina SX', 'form.occhio.fluor_sx');
                        push('Occhio', 'schirmer_dx', 'Schirmer DX', 'form.occhio.schirmer_dx');
                        push('Occhio', 'schirmer_sx', 'Schirmer SX', 'form.occhio.schirmer_sx');
                        push('Occhio', 'cornea_dx', 'Cornea DX', 'form.occhio.cornea_dx');
                        push('Occhio', 'cornea_sx', 'Cornea SX', 'form.occhio.cornea_sx');
                        push('Occhio', 'camera_dx', 'Camera anteriore DX', 'form.occhio.camera_dx');
                        push('Occhio', 'camera_sx', 'Camera anteriore SX', 'form.occhio.camera_sx');
                        push('Occhio', 'iride_dx', 'Iride e pupilla DX', 'form.occhio.iride_dx');
                        push('Occhio', 'iride_sx', 'Iride e pupilla SX', 'form.occhio.iride_sx');
                        push('Occhio', 'cristallino_dx', 'Cristallino DX', 'form.occhio.cristallino_dx');
                        push('Occhio', 'cristallino_sx', 'Cristallino SX', 'form.occhio.cristallino_sx');
                        push('Occhio', 'vitreo_dx', 'Vitreo DX', 'form.occhio.vitreo_dx');
                        push('Occhio', 'vitreo_sx', 'Vitreo SX', 'form.occhio.vitreo_sx');
                        push('Occhio', 'fondo_dx', 'Fondo DX', 'form.occhio.fondo_dx');
                        push('Occhio', 'fondo_sx', 'Fondo SX', 'form.occhio.fondo_sx');
                        push('Occhio', 'iop_dx', 'IOP DX (mm Hg)', 'form.occhio.iop_dx');
                        push('Occhio', 'iop_sx', 'IOP SX (mm Hg)', 'form.occhio.iop_sx');

                        return out;
                    }

                    function buildLibraryFromPreset(presetJson) {
                        var preset = safeParseJson(presetJson, presetJson);
                        var out = [];
                        var seen = {};
                        (preset.items || []).forEach(function(it){
                            var k = String(it.key || '');
                            if (!k || seen[k]) return;
                            seen[k] = true;
                            out.push({
                                // Keep it vet-friendly: show the label as in the form, not the dev key.
                                label: itemDisplayName(it),
                                item: JSON.parse(JSON.stringify(it))
                            });
                        });

                        // Extra fields not included in the preset default map (mostly for OFTALMO).
                        buildExtraLibrary(state.kind).forEach(function(def){
                            var k = String((def && def.item && def.item.key) ? def.item.key : '');
                            if (!k || seen[k]) return;
                            seen[k] = true;
                            out.push(def);
                        });

                        return out;
                    }

                    function mergeLibraryWithMap(baseLib, mapItems) {
                        // Ensures custom/generated fields present in the current map remain
                        // available in the "Aggiungi campo" select, even if they are not
                        // part of the built-in preset.
                        var seen = {};
                        (baseLib || []).forEach(function(def){
                            var k = String((def && def.item && def.item.key) ? def.item.key : '');
                            if (k) seen[k] = true;
                        });
                        (mapItems || []).forEach(function(it){
                            var k = String(it.key || '');
                            if (!k || seen[k]) return;
                            seen[k] = true;
                            baseLib.push({
                                label: itemDisplayName(it),
                                item: JSON.parse(JSON.stringify(it))
                            });
                        });
                        return baseLib;
                    }

                    function uniqSortedInt(arr) {
                        var out = [];
                        (arr || []).forEach(function(v){
                            var n = parseInt(v, 10);
                            if (isNaN(n)) return;
                            if (out.indexOf(n) === -1) out.push(n);
                        });
                        out.sort(function(a,b){ return a-b; });
                        return out;
                    }

                    function isSelected(idx) {
                        return (state.selectedIndices || []).indexOf(idx) !== -1;
                    }

                    function setSelection(indices, primaryIdx) {
                        var max = (state.map && state.map.items) ? (state.map.items.length) : 0;
                        var clean = [];
                        (indices || []).forEach(function(i){
                            var n = parseInt(i, 10);
                            if (isNaN(n)) return;
                            if (n < 0 || n >= max) return;
                            clean.push(n);
                        });
                        clean = uniqSortedInt(clean);
                        state.selectedIndices = clean;

                        var p = parseInt(primaryIdx, 10);
                        if (isNaN(p) || clean.indexOf(p) === -1) {
                            p = clean.length ? clean[clean.length - 1] : -1;
                        }
                        state.primaryIndex = p;

                        renderList();
                        renderOverlay();
                        renderProps();
                    }

                    function handleSelect(idx, ev) {
                        idx = parseInt(idx, 10);
                        if (isNaN(idx) || !state.map || !state.map.items) return;
                        if (idx < 0 || idx >= state.map.items.length) return;

                        var meta = !!(ev && (ev.metaKey || ev.ctrlKey));
                        var shift = !!(ev && ev.shiftKey);

                        // Shift: range selection from primary index
                        if (shift && state.primaryIndex >= 0) {
                            var a = state.primaryIndex;
                            var b = idx;
                            var lo = Math.min(a, b);
                            var hi = Math.max(a, b);
                            var range = [];
                            for (var i = lo; i <= hi; i++) range.push(i);
                            if (meta) {
                                range = uniqSortedInt((state.selectedIndices || []).concat(range));
                            }
                            setSelection(range, idx);
                            return;
                        }

                        // Meta/Ctrl: toggle
                        if (meta) {
                            var cur = (state.selectedIndices || []).slice();
                            var pos = cur.indexOf(idx);
                            if (pos >= 0) cur.splice(pos, 1);
                            else cur.push(idx);
                            setSelection(cur, idx);
                            return;
                        }

                        // Default: if the field is already selected, keep the group and only
                        // update the primary selection (useful for dragging the whole group).
                        if (isSelected(idx) && (state.selectedIndices || []).length) {
                            state.primaryIndex = idx;
                            renderList();
                            renderOverlay();
                            renderProps();
                        } else {
                            setSelection([idx], idx);
                        }
                    }

                    function renderProps() {
                        var items = (state.map && state.map.items) ? state.map.items : [];
                        var sel = (state.selectedIndices || []).slice();
                        // sanitize selection
                        sel = sel.filter(function(i){ return typeof i === 'number' && i >= 0 && i < items.length; });
                        sel = uniqSortedInt(sel);
                        state.selectedIndices = sel;
                        if (sel.length === 0) {
                            state.primaryIndex = -1;
                            propsEl.innerHTML = '<div style="font-size:12px;color:#666;">Seleziona un campo per modificarne posizione e stile. <br><span style="color:#6b7280;">Ctrl/⌘+click per selezionare più campi, Shift+click per intervallo.</span></div>';
                            return;
                        }

                        if (sel.length > 1) {
                            // --- Multi selection / group edit ---
                            propsEl.innerHTML = '';

                            var header = document.createElement('div');
                            header.className = 'vr-editor-props-title';
                            header.textContent = sel.length + ' campi selezionati';
                            propsEl.appendChild(header);

                            var hint = document.createElement('div');
                            hint.style.fontSize = '12px';
                            hint.style.color = '#6b7280';
                            hint.style.marginBottom = '10px';
                            hint.textContent = 'Suggerimenti: Ctrl/⌘+click per aggiungere/rimuovere; Shift+click per selezionare un intervallo; trascina un riquadro per spostare tutto il gruppo.';
                            propsEl.appendChild(hint);

                            function row(label, inputEl) {
                                var r = document.createElement('div');
                                r.className = 'vr-editor-prop-row';
                                var l = document.createElement('label');
                                l.textContent = label;
                                r.appendChild(l);
                                r.appendChild(inputEl);
                                return r;
                            }

                            function numInput(val, step, min, max) {
                                var inp = document.createElement('input');
                                inp.className = 'vr-input';
                                inp.type = 'number';
                                inp.step = String(step);
                                if (min !== null) inp.min = String(min);
                                if (max !== null) inp.max = String(max);
                                inp.value = (val === null || typeof val === 'undefined') ? '' : String(val);
                                return inp;
                            }

                            function common(getter) {
                                var v = null;
                                for (var i=0;i<sel.length;i++) {
                                    var it = items[sel[i]];
                                    if (!it) continue;
                                    ensureItemDefaults(it);
                                    var cur = getter(it);
                                    if (i === 0) v = cur;
                                    else if (String(cur) !== String(v)) return null;
                                }
                                return v;
                            }

                            // Group move (offset)
                            var dxInp = numInput(0, 0.1, -210, 210);
                            var dyInp = numInput(0, 0.1, -297, 297);
                            propsEl.appendChild(row('Sposta X (mm)', dxInp));
                            propsEl.appendChild(row('Sposta Y (mm)', dyInp));

                            var moveBtn = document.createElement('button');
                            moveBtn.type = 'button';
                            moveBtn.className = 'vr-button vr-button-secondary';
                            moveBtn.textContent = 'Applica spostamento al gruppo';
                            moveBtn.addEventListener('click', function(){
                                var dx = parseFloat(dxInp.value) || 0;
                                var dy = parseFloat(dyInp.value) || 0;
                                if (dx === 0 && dy === 0) return;
                                sel.forEach(function(idx){
                                    var it = items[idx];
                                    if (!it) return;
                                    ensureItemDefaults(it);
                                    var x = parseFloat(it.x_mm) || 0;
                                    var y = parseFloat(it.y_mm) || 0;
                                    it.x_mm = clamp(x + dx, 0, 210);
                                    it.y_mm = clamp(y + dy, 0, 297);
                                    if (typeof it.__applyPos === 'function') it.__applyPos();
                                });
                                dxInp.value = '0';
                                dyInp.value = '0';
                                renderList();
                                renderProps();
                            });
                            propsEl.appendChild(moveBtn);

                            propsEl.appendChild(document.createElement('hr'));

                            // Common font size
                            var fs = common(function(it){ return parseFloat(it.font_size) || 9; });
                            var fsInp = numInput(fs, 1, 6, 20);
                            if (fs === null) fsInp.placeholder = '—';
                            fsInp.addEventListener('input', function(){
                                var v = parseFloat(fsInp.value);
                                if (isNaN(v)) return;
                                v = clamp(v, 6, 20);
                                sel.forEach(function(idx){
                                    var it = items[idx];
                                    if (!it) return;
                                    ensureItemDefaults(it);
                                    it.font_size = v;
                                });
                            });
                            propsEl.appendChild(row('Font (pt)', fsInp));

                            // Common alignment
                            var al = common(function(it){ return String(it.align || 'L'); });
                            var alignSel = document.createElement('select');
                            alignSel.className = 'vr-input';
                            var oa0 = document.createElement('option'); oa0.value=''; oa0.textContent='— Invariato —';
                            alignSel.appendChild(oa0);
                            [['L','Sinistra'],['C','Centro'],['R','Destra']].forEach(function(a){
                                var o = document.createElement('option');
                                o.value = a[0]; o.textContent = a[1];
                                alignSel.appendChild(o);
                            });
                            alignSel.value = (al === null) ? '' : al;
                            alignSel.addEventListener('change', function(){
                                if (!alignSel.value) return;
                                sel.forEach(function(idx){
                                    var it = items[idx];
                                    if (!it) return;
                                    ensureItemDefaults(it);
                                    it.align = alignSel.value;
                                });
                            });
                            propsEl.appendChild(row('Allineamento', alignSel));

                            // Label printing group option
                            var showCommon = common(function(it){
                                return ('show_label' in it) ? (!!it.show_label) : null;
                            });
                            var showSel = document.createElement('select');
                            showSel.className = 'vr-input';
                            var s0 = document.createElement('option'); s0.value=''; s0.textContent='— Titolo invariato —';
                            var s1 = document.createElement('option'); s1.value='1'; s1.textContent='Mostra titolo campo';
                            var s2 = document.createElement('option'); s2.value='0'; s2.textContent='Nascondi titolo campo';
                            showSel.appendChild(s0); showSel.appendChild(s1); showSel.appendChild(s2);
                            showSel.value = (showCommon === null) ? '' : (showCommon ? '1' : '0');
                            showSel.addEventListener('change', function(){
                                if (showSel.value === '') return;
                                var on = (showSel.value === '1');
                                sel.forEach(function(idx){
                                    var it = items[idx];
                                    if (!it) return;
                                    ensureItemDefaults(it);
                                    it.show_label = on ? 1 : 0;
                                    if (on) {
                                        // Default printed label to the UI label if missing.
                                        if (!it.label || String(it.label).trim() === '') {
                                            it.label = String(it.ui_label || itemDisplayName(it) || '').trim();
                                        }
                                        if (!it.label_mode || String(it.label_mode) === 'none') it.label_mode = 'inline';
                                    }
                                });
                            });
                            propsEl.appendChild(row('Titolo campo (stampa)', showSel));

                            // Delete group
                            propsEl.appendChild(document.createElement('hr'));
                            var rm = document.createElement('button');
                            rm.type = 'button';
                            rm.className = 'vr-button vr-button-secondary';
                            rm.textContent = 'Rimuovi campi selezionati';
                            rm.addEventListener('click', function(){
                                if (!confirm('Rimuovere ' + sel.length + ' campi dalla mappa?')) return;
                                // Remove from highest index to lowest to avoid shifting
                                var sorted = sel.slice().sort(function(a,b){ return b-a; });
                                sorted.forEach(function(i){
                                    if (i >= 0 && i < items.length) items.splice(i, 1);
                                });
                                setSelection([], -1);
                                renderAddLibrary();
                            });
                            propsEl.appendChild(rm);

                            return;
                        }

                        // --- Single selection ---
                        var idx = sel[0];
                        state.primaryIndex = idx;
                        var it = items[idx];
                        if (!it) {
                            setSelection([], -1);
                            return;
                        }
                        ensureItemDefaults(it);

                        var elType = String(it.type || 'text').toLowerCase();

                        // --- Graphic elements: logo / line / signature ---
                        if (elType && elType !== 'text') {
                            propsEl.innerHTML = '';

                            var header = document.createElement('div');
                            header.className = 'vr-editor-props-title';
                            header.textContent = itemDisplayName(it);
                            propsEl.appendChild(header);

                            function row(label, inputEl) {
                                var r = document.createElement('div');
                                r.className = 'vr-editor-prop-row';
                                var l = document.createElement('label');
                                l.textContent = label;
                                r.appendChild(l);
                                r.appendChild(inputEl);
                                return r;
                            }

                            function numInput(val, step, min, max, onChange) {
                                var inp = document.createElement('input');
                                inp.className = 'vr-input';
                                inp.type = 'number';
                                inp.step = String(step);
                                if (min !== null) inp.min = String(min);
                                if (max !== null) inp.max = String(max);
                                inp.value = String(val);
                                inp.addEventListener('input', function(){ onChange(parseFloat(inp.value)); });
                                return inp;
                            }

                            function colorInput(val, onChange) {
                                var inp = document.createElement('input');
                                inp.className = 'vr-input';
                                inp.type = 'color';
                                var v = String(val || '').trim();
                                if (!/^#[0-9A-Fa-f]{6}$/.test(v)) v = '#111827';
                                inp.value = v;
                                inp.style.height = '40px';
                                inp.style.padding = '6px';
                                inp.addEventListener('input', function(){ onChange(String(inp.value || '').trim()); });
                                return inp;
                            }

                            propsEl.appendChild(row('X (mm)', numInput(it.x_mm, 0.1, 0, 210, function(v){ it.x_mm = clamp(v,0,210); refreshItem(); })));
                            propsEl.appendChild(row('Y (mm)', numInput(it.y_mm, 0.1, 0, 297, function(v){ it.y_mm = clamp(v,0,297); refreshItem(); })));
                            propsEl.appendChild(row('W (mm)', numInput(it.w_mm, 0.1, 5, 210, function(v){ it.w_mm = clamp(v,5,210); refreshItem(); })));

                            // LOGO / IMAGE
                            if (elType === 'image' || elType === 'logo') {
                                propsEl.appendChild(row('H (mm)', numInput(it.h_mm, 0.1, 5, 297, function(v){ it.h_mm = clamp(v,5,297); refreshItem(); })));
                                var srcInfo = document.createElement('div');
                                srcInfo.style.fontSize = '12px';
                                srcInfo.style.color = '#374151';
                                srcInfo.textContent = 'Sorgente immagine: Logo studio';
                                propsEl.appendChild(row('Immagine', srcInfo));
                            }

                            // LINE
                            if (elType === 'line') {
                                propsEl.appendChild(row('Spessore (mm)', numInput(it.h_mm, 0.1, 0.2, 20, function(v){ it.h_mm = clamp(v,0.2,20); refreshItem(); })));
                                propsEl.appendChild(row('Colore', colorInput(it.color_hex || '#0F766E', function(v){ it.color_hex = v; })));
                            }

                            // SIGNATURE
                            if (elType === 'signature') {
                                propsEl.appendChild(row('H (mm)', numInput(it.h_mm, 0.1, 6, 40, function(v){ it.h_mm = clamp(v,6,40); refreshItem(); })));
                                propsEl.appendChild(row('Spessore linea (mm)', numInput(it.line_thickness_mm || 0.3, 0.1, 0.2, 5, function(v){ it.line_thickness_mm = clamp(v,0.2,5); })));
                                propsEl.appendChild(row('Colore', colorInput(it.color_hex || '#111827', function(v){ it.color_hex = v; })));

                                var sigWrap = document.createElement('div');
                                sigWrap.style.display = 'flex';
                                sigWrap.style.alignItems = 'center';
                                sigWrap.style.gap = '10px';
                                var sigChk = document.createElement('input');
                                sigChk.type = 'checkbox';
                                sigChk.checked = !!it.show_label;
                                var sigLbl = document.createElement('label');
                                sigLbl.textContent = 'Stampa testo firma';
                                sigLbl.style.fontSize = '12px';
                                sigLbl.style.color = '#374151';
                                sigWrap.appendChild(sigChk);
                                sigWrap.appendChild(sigLbl);
                                sigChk.addEventListener('change', function(){ it.show_label = sigChk.checked ? 1 : 0; renderProps(); });
                                propsEl.appendChild(sigWrap);

                                if (sigChk.checked) {
                                    var lblInp = document.createElement('input');
                                    lblInp.className = 'vr-input';
                                    lblInp.type = 'text';
                                    lblInp.value = String(it.label || it.ui_label || 'Firma');
                                    lblInp.addEventListener('input', function(){ it.label = String(lblInp.value || '').trim(); });
                                    propsEl.appendChild(row('Testo firma', lblInp));

                                    propsEl.appendChild(row('Font testo (pt)', numInput(it.label_font_size || 9, 1, 6, 18, function(v){ it.label_font_size = clamp(v,6,18); })));
                                }
                            }

                            // Remove
                            var rm = document.createElement('button');
                            rm.type = 'button';
                            rm.className = 'vr-button vr-button-secondary';
                            rm.textContent = 'Rimuovi elemento';
                            rm.addEventListener('click', function(){
                                if (!confirm('Rimuovere questo elemento dalla mappa?')) return;
                                items.splice(idx, 1);
                                setSelection([], -1);
                                renderAddLibrary();
                            });
                            propsEl.appendChild(document.createElement('div'));
                            propsEl.appendChild(rm);

                            function refreshItem() {
                                if (typeof it.__applyPos === 'function') it.__applyPos();
                                renderList();
                            }

                            return;
                        }

                        var isBox = (parseFloat(it.h_mm) || 0) > 0;
                        var labelMode = String(it.label_mode || 'inline');
                        var hasPrintLabel = (String(it.label || '').trim() !== '');
                        // New flag (preferred): show_label. Backward compatible with label_mode.
                        // For legacy maps, consider "label_mode" only if a printable label exists.
                        var showLabel = ('show_label' in it)
                            ? !!it.show_label
                            : (hasPrintLabel && labelMode !== 'none' && labelMode !== 'off' && labelMode !== '0');

                        propsEl.innerHTML = '';

                        var header = document.createElement('div');
                        header.className = 'vr-editor-props-title';
                        header.textContent = itemDisplayName(it);
                        propsEl.appendChild(header);

                        function row(label, inputEl) {
                            var r = document.createElement('div');
                            r.className = 'vr-editor-prop-row';
                            var l = document.createElement('label');
                            l.textContent = label;
                            r.appendChild(l);
                            r.appendChild(inputEl);
                            return r;
                        }

                        function numInput(val, step, min, max, onChange) {
                            var inp = document.createElement('input');
                            inp.className = 'vr-input';
                            inp.type = 'number';
                            inp.step = String(step);
                            if (min !== null) inp.min = String(min);
                            if (max !== null) inp.max = String(max);
                            inp.value = String(val);
                            inp.addEventListener('input', function(){ onChange(parseFloat(inp.value)); });
                            return inp;
                        }

                        propsEl.appendChild(row('X (mm)', numInput(it.x_mm, 0.1, 0, 210, function(v){ it.x_mm = clamp(v,0,210); refreshItem(); })));
                        propsEl.appendChild(row('Y (mm)', numInput(it.y_mm, 0.1, 0, 297, function(v){ it.y_mm = clamp(v,0,297); refreshItem(); })));
                        propsEl.appendChild(row('W (mm)', numInput(it.w_mm, 0.1, 5, 210, function(v){ it.w_mm = clamp(v,5,210); refreshItem(); })));

                        var typeSel = document.createElement('select');
                        typeSel.className = 'vr-input';
                        var o1 = document.createElement('option'); o1.value='single'; o1.textContent='Riga singola';
                        var o2 = document.createElement('option'); o2.value='box'; o2.textContent='Box multi-line';
                        typeSel.appendChild(o1); typeSel.appendChild(o2);
                        typeSel.value = isBox ? 'box' : 'single';
                        typeSel.addEventListener('change', function(){
                            if (typeSel.value === 'single') {
                                it.h_mm = 0;
                            } else {
                                var cur = parseFloat(it.h_mm) || 0;
                                it.h_mm = cur > 0 ? cur : 20;
                            }
                            renderProps();
                            refreshItem();
                        });
                        propsEl.appendChild(row('Tipo campo', typeSel));

                        if (typeSel.value === 'box') {
                            propsEl.appendChild(row('H (mm)', numInput(it.h_mm, 0.1, 5, 297, function(v){ it.h_mm = clamp(v,5,297); refreshItem(); })));
                        }

                        propsEl.appendChild(document.createElement('hr'));

                        // Font / align
                        propsEl.appendChild(row('Font (pt)', numInput(it.font_size, 1, 6, 20, function(v){ it.font_size = clamp(v,6,20); } )));

                        var alignSel = document.createElement('select');
                        alignSel.className = 'vr-input';
                        [['L','Sinistra'],['C','Centro'],['R','Destra']].forEach(function(a){
                            var o = document.createElement('option');
                            o.value = a[0]; o.textContent = a[1];
                            alignSel.appendChild(o);
                        });
                        alignSel.value = String(it.align || 'L');
                        alignSel.addEventListener('change', function(){ it.align = alignSel.value; });
                        propsEl.appendChild(row('Allineamento', alignSel));

                        // Label printing
                        var labWrap = document.createElement('div');
                        labWrap.style.display = 'flex';
                        labWrap.style.alignItems = 'center';
                        labWrap.style.gap = '10px';
                        var labChk = document.createElement('input');
                        labChk.type = 'checkbox';
                        labChk.checked = showLabel;
                        var labLbl = document.createElement('label');
                        labLbl.textContent = 'Stampa anche il nome del campo (come nel form)';
                        labLbl.style.fontSize = '12px';
                        labLbl.style.color = '#374151';
                        labWrap.appendChild(labChk);
                        labWrap.appendChild(labLbl);
                        labChk.addEventListener('change', function(){
                            if (labChk.checked) {
                                it.show_label = 1;
                                // Default printed label: the human label shown in the form/editor.
                                if (!hasPrintLabel) it.label = String(it.ui_label || itemDisplayName(it) || '').trim();
                                if (!it.label_mode || String(it.label_mode) === 'none') it.label_mode = 'inline';
                            } else {
                                it.show_label = 0;
                            }
                            renderProps();
                        });
                        propsEl.appendChild(labWrap);

                        // If label printing is enabled, allow editing label text and mode.
                        if (labChk.checked) {
                            var lblInp = document.createElement('input');
                            lblInp.className = 'vr-input';
                            lblInp.type = 'text';
                            lblInp.value = String(it.label || it.ui_label || itemDisplayName(it));
                            lblInp.addEventListener('input', function(){
                                it.label = String(lblInp.value || '').trim();
                            });
                            propsEl.appendChild(row('Titolo stampato', lblInp));

                            var modeSel = document.createElement('select');
                            modeSel.className = 'vr-input';
                            var mi = document.createElement('option'); mi.value='inline'; mi.textContent='Inline (es. Titolo: valore)';
                            var ma = document.createElement('option'); ma.value='above'; ma.textContent='Sopra (Titolo su riga separata)';
                            modeSel.appendChild(mi); modeSel.appendChild(ma);
                            modeSel.value = (String(it.label_mode || 'inline') === 'above') ? 'above' : 'inline';
                            modeSel.addEventListener('change', function(){
                                it.label_mode = modeSel.value;
                            });
                            propsEl.appendChild(row('Posizione titolo', modeSel));
                        }

                        // Remove
                        var rm = document.createElement('button');
                        rm.type = 'button';
                        rm.className = 'vr-button vr-button-secondary';
                        rm.textContent = 'Rimuovi campo';
                        rm.addEventListener('click', function(){
                            if (!confirm('Rimuovere questo campo dalla mappa?')) return;
                            items.splice(idx, 1);
                            setSelection([], -1);
                            renderAddLibrary();
                        });
                        propsEl.appendChild(document.createElement('div'));
                        propsEl.appendChild(rm);

                        function refreshItem() {
                            // Update overlay box without full re-render if possible
                            if (typeof it.__applyPos === 'function') it.__applyPos();
                            renderList();
                        }
                    }

                    // Drag/resize
                    function pointMmFromEvent(ev) {
                        var r = stage.getBoundingClientRect();
                        var x = (ev.clientX - r.left) / r.width * 210.0;
                        var y = (ev.clientY - r.top) / r.height * 297.0;
                        return {x: x, y: y};
                    }

                    function startDrag(ev, idx) {
                        if (!state.map || !state.map.items) return;

                        // Ensure the dragged item is part of the selection.
                        if (!isSelected(idx)) {
                            setSelection([idx], idx);
                        }

                        var items = state.map.items;
                        var sel = (state.selectedIndices || []).slice();
                        if (!sel.length) sel = [idx];

                        var start = pointMmFromEvent(ev);

                        // Snapshot original positions for all selected items.
                        var snap = sel.map(function(i){
                            var it = items[i];
                            if (!it) return null;
                            ensureItemDefaults(it);
                            return { idx: i, x: parseFloat(it.x_mm) || 0, y: parseFloat(it.y_mm) || 0 };
                        }).filter(function(v){ return v !== null; });

                        overlay.setPointerCapture(ev.pointerId);

                        function move(e) {
                            var p = pointMmFromEvent(e);
                            var dx = p.x - start.x;
                            var dy = p.y - start.y;
                            snap.forEach(function(s){
                                var it = items[s.idx];
                                if (!it) return;
                                it.x_mm = clamp(s.x + dx, 0, 210);
                                it.y_mm = clamp(s.y + dy, 0, 297);
                                if (typeof it.__applyPos === 'function') it.__applyPos();
                            });
                            renderList();
                            renderProps();
                        }
                        function up(e) {
                            overlay.releasePointerCapture(ev.pointerId);
                            overlay.removeEventListener('pointermove', move);
                            overlay.removeEventListener('pointerup', up);
                            overlay.removeEventListener('pointercancel', up);
                        }
                        overlay.addEventListener('pointermove', move);
                        overlay.addEventListener('pointerup', up);
                        overlay.addEventListener('pointercancel', up);
                    }

                    function startResize(ev, idx) {
                        var it = state.map.items[idx];
                        if (!it) return;
                        ensureItemDefaults(it);
                        var start = pointMmFromEvent(ev);
                        var sw = parseFloat(it.w_mm) || 10;
                        var sh = parseFloat(it.h_mm) || 0;
                        var isBox = sh > 0;

                        overlay.setPointerCapture(ev.pointerId);

                        function move(e) {
                            var p = pointMmFromEvent(e);
                            var dw = p.x - start.x;
                            var dh = p.y - start.y;
                            it.w_mm = clamp(sw + dw, 5, 210);

                            // If it was a single-line field, resizing vertically turns it into a box.
                            var newH = (isBox ? sh : 0) + dh;
                            if (newH > 4) {
                                it.h_mm = clamp(newH, 5, 297);
                            } else {
                                it.h_mm = 0;
                            }

                            if (typeof it.__applyPos === 'function') it.__applyPos();
                            renderList();
                            renderProps();
                        }
                        function up(e) {
                            overlay.releasePointerCapture(ev.pointerId);
                            overlay.removeEventListener('pointermove', move);
                            overlay.removeEventListener('pointerup', up);
                            overlay.removeEventListener('pointercancel', up);
                        }
                        overlay.addEventListener('pointermove', move);
                        overlay.addEventListener('pointerup', up);
                        overlay.addEventListener('pointercancel', up);
                    }

                    function openEditor(sheetKey) {
                        sheetKey = (sheetKey || '').toString().trim();
                        if (sheetKey === 'oftalmologica') sheetKey = 'oftalmo';

                        var sheet = vrGetSheet(sheetKey) || vrGetSheet(currentSheetKey);
                        if (!sheet) {
                            sheet = { key: 'clinica', kind: 'clinica', label: 'Clinica / generale', presetJson: presetClinicaJson, fields: [] };
                            sheetKey = 'clinica';
                        }

                        var kind = (sheet.kind || '').toString().trim();
                        if (kind === 'oftalmologica') kind = 'oftalmo';
                        if (!kind) {
                            kind = (sheetKey === 'oftalmo') ? 'oftalmo' : (sheetKey.indexOf('form:') === 0 ? 'custom' : 'clinica');
                        }

                        state.sheetKey = sheetKey;
                        state.sheet = sheet;
                        state.kind = kind;
                        state.humanDict = null;

                        // Human labels for custom modules
                        if (kind === 'custom' && sheet.fields && Array.isArray(sheet.fields)) {
                            var d = {};
                            sheet.fields.forEach(function(f){
                                if (!f) return;
                                var k = (f.key || '').toString().trim();
                                if (!k) return;
                                var lbl = (f.label || '').toString().trim();
                                if (!lbl) lbl = k;
                                d['cf_' + k] = lbl;
                                d[k] = lbl;
                            });
                            state.humanDict = d;
                        }

                        state.textarea = tMap;
                        state.previewImg = document.getElementById('tpl_preview_current');
                        state.presetJson = (sheet.presetJson && sheet.presetJson.trim()) ? sheet.presetJson : (kind === 'oftalmo' ? presetOftalmoJson : presetClinicaJson);

                        if (!state.textarea || !state.previewImg) return;

                        // Parse current map; fall back to the preset JSON for the selected scheda.
                        // (The editor must never end up with an empty map just because fallback parsing failed.)
                        state.map = safeParseJson(state.textarea.value || '', state.presetJson);
                        state.map = ensureMapDefaults(state.map);
                        if (!state.map.items) state.map.items = [];

                        state.library = mergeLibraryWithMap(buildLibraryFromPreset(state.presetJson), state.map.items || []);

                        modalTitle.textContent = 'Editor template – ' + (sheet.label || sheetKey);
                        kindHint.textContent = 'Trascina per allineare i campi al tuo sfondo.';

                        // Background
                        bgImg.src = state.previewImg.getAttribute('src');

                        // Reset scroll so the TOP of the page is always reachable.
                        if (canvasWrap) {
                            canvasWrap.scrollTop = 0;
                            canvasWrap.scrollLeft = 0;
                        }

                        // Reset selection
                        state.selectedIndices = [];
                        state.primaryIndex = -1;

                        // Zoom reset (we will auto-fit after image load)
                        zoom.value = '100';
                        zoomVal.textContent = '100%';
                        stage.style.width = '720px';

                        renderList();
                        renderOverlay();
                        renderProps();
                        renderAddLibrary();

                        modal.style.display = 'block';
                        modal.setAttribute('aria-hidden', 'false');

                        // Auto-fit to show the full A4 page, then scroll to top.
                        requestAnimationFrame(function(){
                            if (typeof vrTplFitPage === 'function') vrTplFitPage();
                            if (canvasWrap) { canvasWrap.scrollTop = 0; canvasWrap.scrollLeft = 0; }
                        });
                    }

                    function closeEditor() {
                        modal.style.display = 'none';
                        modal.setAttribute('aria-hidden', 'true');
                        state.kind = null;
                        state.sheetKey = null;
                        state.sheet = null;
                        state.humanDict = null;
                        state.textarea = null;
                        state.previewImg = null;
                        state.map = null;
                        state.selectedIndices = [];
                        state.primaryIndex = -1;
                    }

                    modalClose.addEventListener('click', closeEditor);
                    modal.addEventListener('click', function(ev){
                        if (ev.target === modal) closeEditor();
                    });

                    // Open editor buttons
                    document.querySelectorAll('[data-open-template-editor]').forEach(function(btn){
                        btn.addEventListener('click', function(){
                            var sheetKey = btn.getAttribute('data-open-template-editor');
                            openEditor(sheetKey);
                        });
                    });

                    // Reset mapping buttons (outside modal)
                    document.querySelectorAll('[data-reset-template-map]').forEach(function(btn){
                        btn.addEventListener('click', function(){
                            var sheetKey = btn.getAttribute('data-reset-template-map') || '';
                            var sheet = vrGetSheet(sheetKey) || vrGetSheet(currentSheetKey);
                            var isOft = (sheetKey === 'oftalmo' || sheetKey === 'oftalmologica' || (sheet && sheet.kind === 'oftalmo'));
                            var preset = (sheet && sheet.presetJson) ? sheet.presetJson : (isOft ? presetOftalmoJson : presetClinicaJson);
                            if (!tMap) return;
                            if (!confirm('Ripristinare la mappa ai valori di default?')) return;
                            tMap.value = preset;
                        });
                    });

                    // Zoom behaviour
                    zoom.addEventListener('input', function(){
                        var z = parseInt(zoom.value, 10) || 100;
                        zoomVal.textContent = z + '%';
                        var w = Math.round(720 * (z / 100));
                        stage.style.width = w + 'px';
                    });

                    // Fit page: compute a zoom so the entire A4 is visible.
                    window.vrTplFitPage = function(){
                        if (!canvasWrap) return;
                        var pad = 32; // wrap padding (16*2)
                        var availW = Math.max(200, canvasWrap.clientWidth - pad);
                        var availH = Math.max(200, canvasWrap.clientHeight - pad);
                        var ratio = 297.0 / 210.0;
                        var wFit = Math.min(availW, availH / ratio);
                        // Convert to zoom % relative to the base width 720px.
                        var z = Math.round((wFit / 720.0) * 100);
                        z = Math.max(60, Math.min(160, z));
                        zoom.value = String(z);
                        zoomVal.textContent = z + '%';
                        stage.style.width = Math.round(720 * (z / 100)) + 'px';
                        // Ensure the top is visible.
                        canvasWrap.scrollTop = 0;
                        canvasWrap.scrollLeft = 0;
                    };

                    if (fitBtn) fitBtn.addEventListener('click', function(){
                        if (typeof vrTplFitPage === 'function') vrTplFitPage();
                    });
                    if (topBtn) topBtn.addEventListener('click', function(){
                        if (!canvasWrap) return;
                        canvasWrap.scrollTop = 0;
                        canvasWrap.scrollLeft = 0;
                    });

                    // Add field
                    addBtn.addEventListener('click', function(){
                        if (!state.map) return;
                        var idx = parseInt(addSel.value, 10);
                        if (isNaN(idx) || idx < 0 || idx >= state.library.length) return;
                        var def = state.library[idx];
                        var it = JSON.parse(JSON.stringify(def.item));
                        // Place near top-left with a little offset based on count
                        var n = (state.map.items || []).length;
                        it.x_mm = clamp(10 + (n % 5) * 5, 0, 210);
                        it.y_mm = clamp(40 + (n % 10) * 4, 0, 297);
                        ensureItemDefaults(it);
                        state.map.items.push(it);
                        setSelection([state.map.items.length - 1], state.map.items.length - 1);
                        renderAddLibrary();
                    });

                    // Reset preset (inside modal)
                    resetBtn.addEventListener('click', function(){
                        if (!state.textarea) return;
                        if (!confirm('Ripristinare il preset di default? Le modifiche non salvate andranno perse.')) return;
                        state.map = safeParseJson(state.presetJson, state.presetJson);
                        if (!state.map.items) state.map.items = [];
                        setSelection([], -1);
                        state.library = buildLibraryFromPreset(state.presetJson);
                        renderAddLibrary();
                    });

                    // Save mapping into textarea
                    saveBtn.addEventListener('click', function(){
                        if (!state.textarea || !state.map) return;
                        try {
                            state.textarea.value = JSON.stringify(state.map, null, 2);
                        } catch(e) {
                            alert('Errore: impossibile salvare la mappa.');
                            return;
                        }
                        closeEditor();
                    });

                    // ESC closes
                    document.addEventListener('keydown', function(ev){
                        if (ev.key === 'Escape' && modal.style.display === 'block') closeEditor();
                    });

                    // Ensure all settings forms keep the tab value even if the browser drops querystrings
                    var tabVal = <?php echo json_encode($settings_tab); ?>;
                    document.querySelectorAll('form').forEach(function(f){
                        if (f.querySelector('input[name="settings_tab"]')) return;
                        var inp = document.createElement('input');
                        inp.type = 'hidden';
                        inp.name = 'settings_tab';
                        inp.value = tabVal;
                        f.appendChild(inp);
                    });
                })();
            </script>
        </div>

        </div>

        <div class="vr-settings-section" data-settings-section="visite" style="<?php echo $showVisite ? '' : 'display:none;'; ?>">
            <div class="vr-card">
                <div class="vr-card-header">Moduli visita personalizzati</div>
                <?php if ($vf_error): ?><div class="vr-alert vr-alert-error"><?php echo vr_h($vf_error); ?></div><?php endif; ?>
                <?php if ($vf_success): ?><div class="vr-alert vr-alert-success"><?php echo vr_h($vf_success); ?></div><?php endif; ?>
                <p style="margin:8px 0 0;color:#6b7280;font-size:13px;">
                    Qui puoi creare moduli di visita per specialisti (es. ecografia, dermatologia, ortopedia) senza scrivere codice.
                    I moduli saranno disponibili quando crei una nuova visita nella scheda del paziente.
                </p>

                <div style="margin-top:12px;display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
                    <a class="vr-button" href="index.php?page=settings&tab=visite&form_id=0">Crea nuovo modulo</a>
                </div>

                <div style="margin-top:12px;overflow:auto;">
                    <table class="vr-table" style="min-width:640px;">
                        <thead>
                            <tr>
                                <th>Nome</th>
                                <th>Slug</th>
                                <th style="width:220px;">Azioni</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($visitForms)): ?>
                                <tr><td colspan="3" style="color:#6b7280;">Nessun modulo personalizzato ancora creato.</td></tr>
                            <?php else: foreach ($visitForms as $vf): ?>
                                <tr>
                                    <td><?php echo vr_h($vf['name']); ?></td>
                                    <td><code><?php echo vr_h($vf['slug']); ?></code></td>
                                    <td>
                                        <a class="vr-button vr-button-secondary" href="index.php?page=settings&tab=visite&form_id=<?php echo (int)$vf['id']; ?>">Modifica</a>
                                        <form method="post" style="display:inline;" onsubmit="return confirm('Eliminare il modulo?');">
                                            <input type="hidden" name="page" value="settings">
                                            <input type="hidden" name="settings_tab" value="visite">
                                            <input type="hidden" name="form_action" value="delete_visit_form">
                                            <?php echo vr_staff_csrf_field(); ?>
                                            <input type="hidden" name="delete_form_id" value="<?php echo (int)$vf['id']; ?>">
                                            <button type="submit" class="vr-button" style="background:#ef4444;">Elimina</button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="vr-card" style="margin-top:14px;">
                <div class="vr-card-header"><?php echo $editForm ? 'Editor modulo: ' . vr_h($editForm['name']) : 'Nuovo modulo visita'; ?></div>
                <?php
                    $defArr = ['version'=>1, 'fields'=>[]];
                    if ($editForm && !empty($editForm['definition_json'])) {
                        $tmp = json_decode((string)$editForm['definition_json'], true);
                        if (is_array($tmp)) $defArr = $tmp;
                    }
                    $fields = (is_array($defArr['fields'] ?? null)) ? $defArr['fields'] : [];
                    if (empty($fields)) {
                        $fields = [
                            ['section'=>'', 'label'=>'Motivo visita', 'type'=>'textarea', 'key'=>'motivo', 'options'=>[], 'required'=>0],
                            ['section'=>'', 'label'=>'Esame obiettivo', 'type'=>'textarea', 'key'=>'esame_obiettivo', 'options'=>[], 'required'=>0],
                        ];
                    }
                ?>

                <form method="post">
                    <input type="hidden" name="page" value="settings">
                    <input type="hidden" name="settings_tab" value="visite">
                    <input type="hidden" name="form_action" value="save_visit_form">
                    <?php echo vr_staff_csrf_field(); ?>
                    <input type="hidden" name="form_id" value="<?php echo (int)($editForm['id'] ?? 0); ?>">

                    <label>Nome modulo</label>
                    <input type="text" name="visit_form_name" value="<?php echo vr_h($editForm['name'] ?? ''); ?>" placeholder="Es. Visita ecografica" required>

                    <div style="margin-top:10px;display:flex;justify-content:space-between;align-items:center;gap:8px;flex-wrap:wrap;">
                        <strong style="font-size:13px;">Campi del modulo</strong>
                        <button type="button" class="vr-button vr-button-secondary" id="vr_add_field_row">+ Aggiungi campo</button>
                    </div>

                    <div style="margin-top:10px;overflow:auto;">
                        <table class="vr-table" id="vr_fields_table" style="min-width:840px;">
                            <thead>
                                <tr>
                                    <th style="width:160px;">Sezione</th>
                                    <th>Etichetta (nome visibile)</th>
                                    <th style="width:120px;">Tipo</th>
                                    <th style="width:160px;">Chiave</th>
                                    <th>Opzioni (solo select)</th>
                                    <th style="width:90px;">Obblig.</th>
                                    <th style="width:70px;">&nbsp;</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($fields as $f):
                                    $f = is_array($f) ? $f : [];
                                    $sec = (string)($f['section'] ?? '');
                                    $lbl = (string)($f['label'] ?? '');
                                    $typ = (string)($f['type'] ?? 'text');
                                    $key = (string)($f['key'] ?? '');
                                    $opts = '';
                                    if (!empty($f['options']) && is_array($f['options'])) $opts = implode(', ', array_map('strval', $f['options']));
                                    $req = !empty($f['required']);
                                ?>
                                <tr>
                                    <td><input type="text" name="field_section[]" value="<?php echo vr_h($sec); ?>" placeholder="Es. Anamnesi"></td>
                                    <td><input type="text" name="field_label[]" value="<?php echo vr_h($lbl); ?>" placeholder="Es. Risultato" required></td>
                                    <td>
                                        <select name="field_type[]">
                                            <?php foreach (['text'=>'Testo', 'textarea'=>'Testo lungo', 'number'=>'Numero', 'select'=>'Menu', 'checkbox'=>'Spunta'] as $k=>$t): ?>
                                                <option value="<?php echo vr_h($k); ?>" <?php echo $typ===$k ? 'selected' : ''; ?>><?php echo vr_h($t); ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </td>
                                    <td><input type="text" name="field_key[]" value="<?php echo vr_h($key); ?>" placeholder="auto"></td>
                                    <td><input type="text" name="field_options[]" value="<?php echo vr_h($opts); ?>" placeholder="Opzione 1, Opzione 2"></td>
                                    <td style="text-align:center;"><input type="checkbox" name="field_required[]" value="1" <?php echo $req ? 'checked' : ''; ?>></td>
                                    <td><button type="button" class="vr-button vr-button-secondary vr-remove-row">Rimuovi</button></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>

                    <div style="margin-top:12px;display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
                        <button type="submit" class="vr-button">Salva modulo</button>
                        <a class="vr-button vr-button-secondary" href="index.php?page=settings&tab=visite">Torna all'elenco</a>
                    </div>
                </form>

                <script>
                    (function(){
                        var addBtn = document.getElementById('vr_add_field_row');
                        var tbl = document.getElementById('vr_fields_table');
                        if (!addBtn || !tbl) return;
                        addBtn.addEventListener('click', function(){
                            var tb = tbl.querySelector('tbody');
                            if (!tb) return;
                            var tr = document.createElement('tr');
                            tr.innerHTML = ''
                                + '<td><input type="text" name="field_section[]" value="" placeholder="Es. Anamnesi"></td>'
                                + '<td><input type="text" name="field_label[]" value="" placeholder="Es. Risultato" required></td>'
                                + '<td><select name="field_type[]">'
                                + '<option value="text">Testo</option>'
                                + '<option value="textarea">Testo lungo</option>'
                                + '<option value="number">Numero</option>'
                                + '<option value="select">Menu</option>'
                                + '<option value="checkbox">Spunta</option>'
                                + '</select></td>'
                                + '<td><input type="text" name="field_key[]" value="" placeholder="auto"></td>'
                                + '<td><input type="text" name="field_options[]" value="" placeholder="Opzione 1, Opzione 2"></td>'
                                + '<td style="text-align:center;"><input type="checkbox" name="field_required[]" value="1"></td>'
                                + '<td><button type="button" class="vr-button vr-button-secondary vr-remove-row">Rimuovi</button></td>';
                            tb.appendChild(tr);
                        });
                        tbl.addEventListener('click', function(ev){
                            var t = ev.target;
                            if (t && t.classList && t.classList.contains('vr-remove-row')) {
                                var row = t.closest('tr');
                                if (row && row.parentNode) row.parentNode.removeChild(row);
                            }
                        });
                    })();
                </script>
            </div>
        </div>

        <div class="vr-settings-section" data-settings-section="agenda" style="<?php echo $showAgenda ? '' : 'display:none;'; ?>">
        <div class="vr-card">
            <div class="vr-card-header">Giorni e orari di lavoro</div>
            <?php if ($settings_error): ?>
                <div class="vr-alert vr-alert-error"><?php echo vr_h($settings_error); ?></div>
            <?php endif; ?>
            <?php if ($settings_success): ?>
                <div class="vr-alert vr-alert-success"><?php echo vr_h($settings_success); ?></div>
            <?php endif; ?>

            <p style="font-size:12px;margin-top:0;">Lascia vuoti inizio/fine per i giorni chiusi. Puoi impostare anche una chiusura pomeridiana (es. 09:00-12:30 / 15:00-19:00).</p>

            <form method="post">
                <input type="hidden" name="settings_tab" value="<?php echo vr_h($settings_tab); ?>">
                <input type="hidden" name="form_action" value="save_settings">
                <?php echo vr_staff_csrf_field(); ?>
                <table class="vr-table">
                    <thead>
                    <tr>
                        <th>Giorno</th>
                        <th>Inizio (mattina)</th>
                        <th>Fine (mattina)</th>
                        <th>Inizio (pomeriggio)</th>
                        <th>Fine (pomeriggio)</th>
                        <th>Durata slot (min)</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php for ($w = 1; $w <= 7; $w++):
                        $row = $avail[$w] ?? null;
                        $startVal = $row['start_time'] ?? '';
                        $endVal = $row['end_time'] ?? '';
                        $slotVal = $row['slot_minutes'] ?? 30;
                        $start2Val = $row['start_time2'] ?? '';
                        $end2Val = $row['end_time2'] ?? '';
                    ?>
                        <tr>
                            <td><?php echo vr_h($days[$w]); ?></td>
                            <td><input class="vr-input" type="time" name="start_<?php echo $w; ?>" value="<?php echo vr_h($startVal); ?>"></td>
                            <td><input class="vr-input" type="time" name="end_<?php echo $w; ?>" value="<?php echo vr_h($endVal); ?>"></td>
                            <td><input class="vr-input" type="time" name="start2_<?php echo $w; ?>" value="<?php echo vr_h($start2Val); ?>"></td>
                            <td><input class="vr-input" type="time" name="end2_<?php echo $w; ?>" value="<?php echo vr_h($end2Val); ?>"></td>
                            <td><input class="vr-input" type="number" name="slot_<?php echo $w; ?>" value="<?php echo vr_h($slotVal); ?>"></td>
                        </tr>
                    <?php endfor; ?>
                    </tbody>
                </table>

                <button type="submit" class="vr-button" style="margin-top:8px;">Salva impostazioni</button>
            </form>
        </div>

        </div>

        <div class="vr-settings-section" data-settings-section="backup" style="<?php echo $showBackup ? '' : 'display:none;'; ?>">
        <div class="vr-card">
            <div class="vr-card-header">Backup dell'app (esporta / importa)</div>

            <?php if (!empty($backup_error)): ?>
                <div class="vr-alert vr-alert-error"><?php echo vr_h($backup_error); ?></div>
            <?php endif; ?>
            <?php if (!empty($backup_success)): ?>
                <div class="vr-alert vr-alert-success"><?php echo vr_h($backup_success); ?></div>
            <?php endif; ?>

            <?php if (!$isAdmin): ?>
                <div class="vr-alert vr-alert-error">
                    Solo un utente <strong>Admin</strong> può esportare o ripristinare un backup.
                </div>
            <?php else: ?>

                <p style="margin:8px 0 0;color:#6b7280;font-size:13px;">
                    Il backup crea una <strong>fotografia completa</strong> dell'app: database (proprietari, pazienti, visite, agenda, moduli, template) e tutti i file caricati
                    (allegati, sfondi, logo, timbro).
                </p>

                <div style="display:flex;gap:16px;flex-wrap:wrap;margin-top:12px;">
                    <div style="flex:1;min-width:260px;">
                        <div style="font-weight:600;margin-bottom:6px;">Esporta</div>
                        <form method="post" style="margin-bottom:10px;">
                            <input type="hidden" name="settings_tab" value="<?php echo vr_h($settings_tab); ?>">
                            <input type="hidden" name="form_action" value="export_backup_full">
                            <?php echo vr_staff_csrf_field(); ?>
                            <button type="submit" class="vr-button" <?php echo vr_backup_zip_available() ? '' : 'disabled'; ?>>Scarica backup completo (ZIP)</button>
                            <?php if (!vr_backup_zip_available()): ?>
                                <div style="margin-top:6px;font-size:12px;color:#666;">Nota: ZipArchive non è disponibile sul server. Puoi comunque esportare il solo database (SQLite).</div>
                            <?php endif; ?>
                        </form>

                        <form method="post">
                            <input type="hidden" name="settings_tab" value="<?php echo vr_h($settings_tab); ?>">
                            <input type="hidden" name="form_action" value="export_backup_db">
                            <?php echo vr_staff_csrf_field(); ?>
                            <button type="submit" class="vr-button vr-button-secondary">Scarica solo database (SQLite)</button>
                            <div style="margin-top:6px;font-size:12px;color:#666;">Consigliato come export veloce. Non include allegati e file caricati.</div>
                        </form>
                    </div>

                    <div style="flex:1;min-width:260px;">
                        <div style="font-weight:600;margin-bottom:6px;">Importa / Ripristina</div>
                        <div style="font-size:12px;color:#666;margin-bottom:10px;line-height:1.35;">
                            Attenzione: il ripristino sovrascrive i dati correnti. Prima di procedere, assicurati di aver scaricato un backup.
                        </div>

                        <form method="post" enctype="multipart/form-data">
                            <input type="hidden" name="settings_tab" value="<?php echo vr_h($settings_tab); ?>">
                            <input type="hidden" name="form_action" value="import_backup">
                            <?php echo vr_staff_csrf_field(); ?>
                            <div class="vr-form-row">
                                <label class="vr-label" for="backup_file">File backup (.zip) o database (.sqlite)</label>
                                <input class="vr-input" type="file" id="backup_file" name="backup_file" accept=".zip,.sqlite,.db" required>
                            </div>
                            <div class="vr-form-row">
                                <label class="vr-label" for="backup_confirm_phrase">Conferma (scrivi esattamente)</label>
                                <input class="vr-input" type="text" id="backup_confirm_phrase" name="backup_confirm_phrase" placeholder="voglio ripristinare il backup" required>
                                <div style="margin-top:6px;font-size:12px;color:#666;">Serve per evitare ripristini accidentali.</div>
                            </div>
                            <div class="vr-form-row">
                                <label class="vr-label" style="display:flex;gap:8px;align-items:center;">
                                    <input type="checkbox" name="keep_credentials" value="1" checked>
                                    <span><strong>Mantieni le credenziali attuali</strong> (consigliato)</span>
                                </label>
                                <div style="margin-top:6px;font-size:12px;color:#666;line-height:1.35;">
                                    Per sicurezza, la procedura di ripristino può <strong>mantenere le password attuali</strong> e impedire che un backup (anche vecchio) sovrascriva le credenziali.
                                </div>
                            </div>
                            <button type="submit" class="vr-button" style="background:#b91c1c;">Ripristina backup</button>
                        </form>
                    </div>
                </div>

                <details style="margin-top:14px;">
                    <summary style="font-weight:600;cursor:pointer;">Dove viene salvato il backup?</summary>
                    <div style="margin-top:8px;font-size:12px;color:#666;line-height:1.4;">
                        Il backup completo include:
                        <ul style="margin:6px 0 0 18px;">
                            <li><code>db/vetroom2.sqlite</code> (database proprietari/pazienti/visite)</li>
                            <li><code>uploads/</code> (sfondi template, allegati, logo, timbro...)</li>
                            <li><code>private_uploads/</code> (PDF visite, allegati, documenti onboarding – non accessibili direttamente via web)</li>
                        </ul>
                        Durante l'import, l'app salva automaticamente una copia di sicurezza in <code>data/backups/</code>.
                    </div>
                </details>

            <?php endif; ?>
        </div>
        </div>
        <?php
        vr_layout_end();
        break;
    }
}