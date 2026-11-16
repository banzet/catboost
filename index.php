<?php
// --- PARTIE BACK-END / API PHP & SQLite & SYNCHRO GITHUB ---
$dbFile = __DIR__ . '/catboost.sqlite';

// Restauration automatique depuis GitHub si la base SQLite locale est absente (redémarrage Render)
function restoreBackupFromGitHub() {
    global $dbFile;
    $token = getenv('GITHUB_TOKEN');
    $repo  = getenv('GITHUB_REPO');
    if (!$token || !$repo || file_exists($dbFile)) return;

    $url = "https://api.github.com/repos/{$repo}/contents/backup_catboost.sqlite";
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            "Authorization: Bearer {$token}",
            "User-Agent: Render-PHP-App",
            "Accept: application/vnd.github.v3+json"
        ]
    ]);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode === 200) {
        $data = json_decode($response, true);
        if (!empty($data['content'])) {
            file_put_contents($dbFile, base64_decode($data['content']));
        }
    }
}

// Sauvegarde silencieuse automatique de la base SQLite sur GitHub
function syncBackupToGitHub() {
    global $dbFile;
    $token = getenv('GITHUB_TOKEN');
    $repo  = getenv('GITHUB_REPO');
    if (!$token || !$repo || !file_exists($dbFile)) return;

    $url = "https://api.github.com/repos/{$repo}/contents/backup_catboost.sqlite";
    $headers = [
        "Authorization: Bearer {$token}",
        "User-Agent: Render-PHP-App",
        "Accept: application/vnd.github.v3+json",
        "Content-Type: application/json"
    ];

    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HTTPHEADER => $headers]);
    $res = json_decode(curl_exec($ch), true);
    curl_close($ch);
    $sha = $res['sha'] ?? null;

    $payload = [
        'message' => 'Auto-backup SQLite : ' . date('Y-m-d H:i:s'),
        'content' => base64_encode(file_get_contents($dbFile))
    ];
    if ($sha) $payload['sha'] = $sha;

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST  => 'PUT',
        CURLOPT_POSTFIELDS     => json_encode($payload),
        CURLOPT_HTTPHEADER     => $headers
    ]);
    curl_exec($ch);
    curl_close($ch);
}

// Tenter de restaurer la base au premier chargement si absente
restoreBackupFromGitHub();

try {
    $db = new PDO('sqlite:' . $dbFile);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // Initialisation des tables si elles n'existent pas
    $db->exec("CREATE TABLE IF NOT EXISTS participants (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        nom TEXT NOT NULL,
        prenom TEXT NOT NULL,
        statut TEXT NOT NULL,
        tickets TEXT NOT NULL
    )");

    $db->exec("CREATE TABLE IF NOT EXISTS history (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        date TEXT NOT NULL,
        ticketNum INTEGER NOT NULL,
        statut TEXT NOT NULL
    )");

    $db->exec("CREATE TABLE IF NOT EXISTS settings (
        key TEXT PRIMARY KEY,
        value TEXT
    )");

    // Mot de passe admin par défaut si absent
    $stmt = $db->prepare("SELECT value FROM settings WHERE key = 'password'");
    $stmt->execute();
    if (!$stmt->fetch()) {
        $db->exec("INSERT INTO settings (key, value) VALUES ('password', 'CAT2026')");
    }

    // Taille par défaut du centre de la roue (30%) si absent
    $stmtSize = $db->prepare("SELECT value FROM settings WHERE key = 'wheel_center_size'");
    $stmtSize->execute();
    if (!$stmtSize->fetch()) {
        $db->exec("INSERT INTO settings (key, value) VALUES ('wheel_center_size', '30')");
    }

} catch (Exception $e) {
    // Gestion basique de l'erreur
}

// Gestion des requêtes AJAX / API
if (isset($_GET['action'])) {
    header('Content-Type: application/json');
    $action = $_GET['action'];

    if ($action === 'get_data') {
        $parts = $db->query("SELECT * FROM participants")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($parts as &$p) {
            $p['tickets'] = json_decode($p['tickets'], true);
        }
        $hist = $db->query("SELECT * FROM history ORDER BY id DESC")->fetchAll(PDO::FETCH_ASSOC);
        
        $spinStmt = $db->query("SELECT value FROM settings WHERE key = 'active_spin'");
        $spinRes = $spinStmt->fetch(PDO::FETCH_ASSOC);

        $sizeStmt = $db->query("SELECT value FROM settings WHERE key = 'wheel_center_size'");
        $sizeRes = $sizeStmt->fetch(PDO::FETCH_ASSOC);

        echo json_encode([
            'participants' => $parts,
            'history' => $hist,
            'active_spin' => $spinRes ? json_decode($spinRes['value'], true) : null,
            'wheel_center_size' => $sizeRes ? intval($sizeRes['value']) : 30
        ]);
        exit;
    }

    if ($action === 'verify_password') {
        $input = json_decode(file_get_contents('php://input'), true);
        $inputPass = $input['password'] ?? '';
        $stmt = $db->prepare("SELECT value FROM settings WHERE key = 'password'");
        $stmt->execute();
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        echo json_encode(['success' => ($row && trim($row['value']) === trim($inputPass))]);
        exit;
    }

    if ($action === 'save_participants') {
        $input = json_decode(file_get_contents('php://input'), true);
        if (isset($input['password']) && isset($input['participants'])) {
            $stmt = $db->prepare("SELECT value FROM settings WHERE key = 'password'");
            $stmt->execute();
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row && trim($row['value']) === trim($input['password'])) {
                $db->exec("DELETE FROM participants");
                $stmtIns = $db->prepare("INSERT INTO participants (nom, prenom, statut, tickets) VALUES (?, ?, ?, ?)");
                foreach ($input['participants'] as $p) {
                    $stmtIns->execute([$p['nom'], $p['prenom'], $p['statut'], json_encode($p['tickets'])]);
                }
                syncBackupToGitHub(); // Sauvegarde auto transparente
                echo json_encode(['success' => true]);
                exit;
            }
        }
        echo json_encode(['success' => false, 'error' => 'Unauthorized']);
        exit;
    }

    if ($action === 'clear_all_participants') {
        $input = json_decode(file_get_contents('php://input'), true);
        if (isset($input['password'])) {
            $stmt = $db->prepare("SELECT value FROM settings WHERE key = 'password'");
            $stmt->execute();
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row && trim($row['value']) === trim($input['password'])) {
                $db->exec("DELETE FROM participants");
                syncBackupToGitHub(); // Sauvegarde auto transparente
                echo json_encode(['success' => true]);
                exit;
            }
        }
        echo json_encode(['success' => false]);
        exit;
    }

    if ($action === 'save_center_size') {
        $input = json_decode(file_get_contents('php://input'), true);
        if (isset($input['password']) && isset($input['size'])) {
            $stmt = $db->prepare("SELECT value FROM settings WHERE key = 'password'");
            $stmt->execute();
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row && trim($row['value']) === trim($input['password'])) {
                $sizeVal = max(15, min(50, intval($input['size'])));
                $stmtSize = $db->prepare("INSERT OR REPLACE INTO settings (key, value) VALUES ('wheel_center_size', ?)");
                $stmtSize->execute([$sizeVal]);
                syncBackupToGitHub(); // Sauvegarde auto transparente
                echo json_encode(['success' => true]);
                exit;
            }
        }
        echo json_encode(['success' => false]);
        exit;
    }

    if ($action === 'record_winner') {
        $input = json_decode(file_get_contents('php://input'), true);
        if (isset($input['ticketNum'])) {
            $winnerLabel = 'Inconnu';
            $parts = $db->query("SELECT * FROM participants")->fetchAll(PDO::FETCH_ASSOC);
            foreach ($parts as $p) {
                $tickets = json_decode($p['tickets'], true);
                if (in_array(intval($input['ticketNum']), array_map('intval', $tickets))) {
                    $winnerLabel = $p['nom'] . ' ' . $p['prenom'];
                    break;
                }
            }

            $nowFormatted = date('d/m/Y H:i:s');
            $stmt = $db->prepare("INSERT INTO history (date, ticketNum, statut) VALUES (?, ?, ?)");
            $stmt->execute([$nowFormatted, $input['ticketNum'], $winnerLabel]);

            $spinPayload = json_encode(['ticketNum' => $input['ticketNum'], 'timestamp' => microtime(true)]);
            $stmtSpin = $db->prepare("INSERT OR REPLACE INTO settings (key, value) VALUES ('active_spin', ?)");
            $stmtSpin->execute([$spinPayload]);

            syncBackupToGitHub(); // Sauvegarde auto transparente après tirage
            echo json_encode(['success' => true]);
            exit;
        }
        echo json_encode(['success' => false]);
        exit;
    }

    if ($action === 'reset_spin') {
        $input = json_decode(file_get_contents('php://input'), true);
        if (isset($input['password'])) {
            $stmt = $db->prepare("SELECT value FROM settings WHERE key = 'password'");
            $stmt->execute();
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row && trim($row['value']) === trim($input['password'])) {
                $db->exec("DELETE FROM settings WHERE key = 'active_spin'");
                syncBackupToGitHub();
                echo json_encode(['success' => true]);
                exit;
            }
        }
        echo json_encode(['success' => false]);
        exit;
    }

    if ($action === 'clear_history') {
        $input = json_decode(file_get_contents('php://input'), true);
        if (isset($input['password'])) {
            $stmt = $db->prepare("SELECT value FROM settings WHERE key = 'password'");
            $stmt->execute();
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row && trim($row['value']) === trim($input['password'])) {
                $db->exec("DELETE FROM history");
                syncBackupToGitHub(); // Sauvegarde auto transparente
                echo json_encode(['success' => true]);
                exit;
            }
        }
        echo json_encode(['success' => false]);
        exit;
    }

    if ($action === 'change_password') {
        $input = json_decode(file_get_contents('php://input'), true);
        if (isset($input['old_password']) && isset($input['new_password'])) {
            $stmt = $db->prepare("SELECT value FROM settings WHERE key = 'password'");
            $stmt->execute();
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row && trim($row['value']) === trim($input['old_password'])) {
                $upd = $db->prepare("UPDATE settings SET value = ? WHERE key = 'password'");
                $upd->execute([trim($input['new_password'])]);
                syncBackupToGitHub(); // Sauvegarde auto transparente
                echo json_encode(['success' => true]);
                exit;
            }
        }
        echo json_encode(['success' => false]);
        exit;
    }

    if ($action === 'delete_history_item') {
        $input = json_decode(file_get_contents('php://input'), true);
        if (isset($input['password']) && isset($input['id'])) {
            $stmt = $db->prepare("SELECT value FROM settings WHERE key = 'password'");
            $stmt->execute();
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row && trim($row['value']) === trim($input['password'])) {
                $del = $db->prepare("DELETE FROM history WHERE id = ?");
                $del->execute([$input['id']]);
                syncBackupToGitHub(); // Sauvegarde auto transparente
                echo json_encode(['success' => true]);
                exit;
            }
        }
        echo json_encode(['success' => false]);
        exit;
    }
    exit;
}
?>
<!DOCTYPE html>
<html lang="fr" class="h-full">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>CAT'Boost du pouvoir d'achat</title>
    <!-- Tailwind CSS CDN -->
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    colors: {
                        cat: {
                            darkblue: '#003B8E',
                            green: '#1FA84A',
                            yellow: '#F59E0B',
                            lightblue: '#1D8FE1',
                            gray: '#5B6470',
                            lightbg: '#F4F6F8'
                        }
                    }
                }
            }
        }
    </script>
    <!-- Canvas Confetti CDN -->
    <script src="https://cdn.jsdelivr.net/npm/canvas-confetti@1.6.0/dist/confetti.browser.min.js"></script>
    <style>
        .wheel-container {
            position: relative;
            width: 100%;
            max-width: 380px;
            aspect-ratio: 1 / 1;
            margin: 0 auto;
        }
        @media (min-width: 768px) {
            .wheel-container {
                max-width: 440px;
            }
        }
        canvas#wheelCanvas {
            width: 100%;
            height: 100%;
            border-radius: 50%;
            box-shadow: 0 15px 35px rgba(0, 59, 142, 0.2), 0 5px 15px rgba(0,0,0,0.1);
        }
        .wheel-pointer {
            position: absolute;
            top: -15px;
            left: 50%;
            transform: translateX(-50%);
            width: 0;
            height: 0;
            border-left: 16px solid transparent;
            border-right: 16px solid transparent;
            border-top: 32px solid #003B8E;
            z-index: 50;
            filter: drop-shadow(0 3px 3px rgba(0,0,0,0.3));
        }
    </style>
</head>
<body class="h-full bg-cat-lightbg text-slate-800 flex flex-col font-sans selection:bg-cat-lightblue selection:text-white">

    <!-- En-tête responsive -->
    <header class="bg-cat-darkblue text-white shadow-md border-b-4 border-cat-green">
        <div class="max-w-7xl mx-auto px-4 py-3 sm:px-6 lg:px-8 flex flex-col md:flex-row justify-between items-center gap-3">
            <div class="flex items-center space-x-3 w-full md:w-auto justify-between md:justify-start">
                <div class="flex items-center space-x-3">
                    <div class="w-10 h-10 sm:w-12 sm:h-12 rounded-xl bg-white p-1 flex items-center justify-center overflow-hidden shadow-inner shrink-0">
                        <img src="Logo_CAT.png" alt="Logo CAT" class="w-full h-full object-contain">
                    </div>
                    <div>
                        <h1 class="text-lg sm:text-2xl font-black tracking-tight text-white">CAT'Boost du pouvoir d'achat</h1>
                        <p class="text-[11px] sm:text-xs text-cat-lightblue font-medium">Le tirage au sort officiel</p>
                    </div>
                </div>
            </div>
            
            <div class="flex flex-wrap items-center justify-center md:justify-end gap-2 w-full md:w-auto">
                <div id="workingDayBadge" class="hidden sm:flex px-3 py-1.5 rounded-lg text-xs font-bold bg-slate-800 text-slate-200 border border-slate-700 shadow-sm items-center gap-2">
                    <span id="workingDayDot" class="w-2 h-2 rounded-full bg-cat-green animate-pulse"></span>
                    <span id="workingDayText">Calcul...</span>
                </div>
                
                <nav class="flex bg-slate-900/80 p-1 rounded-xl border border-slate-700 w-full sm:w-auto justify-center">
                    <button onclick="requestAccess('draw')" id="tabDrawBtn" class="flex-1 sm:flex-none px-3 sm:px-4 py-2 rounded-lg text-xs sm:text-sm font-bold transition-all bg-cat-lightblue text-white shadow">
                        Tirage
                    </button>
                    <button onclick="requestAccess('admin')" id="tabAdminBtn" class="flex-1 sm:flex-none px-3 sm:px-4 py-2 rounded-lg text-xs sm:text-sm font-bold transition-all text-slate-300 hover:text-white flex items-center justify-center gap-1">
                        <svg class="w-3.5 h-3.5 text-cat-yellow shrink-0" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M5 9V7a5 5 0 0110 0v2a2 2 0 012 2v5a2 2 0 01-2 2H5a2 2 0 01-2-2v-5a2 2 0 012-2zm8-2v2H7V7a3 3 0 016 0z" clip-rule="evenodd"/></svg>
                        Admin
                    </button>
                    <button onclick="requestAccess('history')" id="tabHistoryBtn" class="flex-1 sm:flex-none px-3 sm:px-4 py-2 rounded-lg text-xs sm:text-sm font-bold transition-all text-slate-300 hover:text-white flex items-center justify-center gap-1">
                        <svg class="w-3.5 h-3.5 text-cat-yellow shrink-0" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M5 9V7a5 5 0 0110 0v2a2 2 0 012 2v5a2 2 0 01-2 2H5a2 2 0 01-2-2v-5a2 2 0 012-2zm8-2v2H7V7a3 3 0 016 0z" clip-rule="evenodd"/></svg>
                        Historique
                    </button>
                </nav>
            </div>
        </div>
    </header>

    <!-- Corps Principal -->
    <main class="flex-1 max-w-7xl w-full mx-auto p-3 sm:p-6 lg:p-8">

        <!-- ONGLET 1 : TIRAGE EN DIRECT -->
        <section id="tabDraw" class="space-y-6">
            <div class="bg-white p-4 sm:p-6 rounded-2xl shadow-sm border border-slate-200 flex flex-col lg:flex-row items-center gap-6 lg:gap-8">
                
                <div class="w-full lg:w-1/2 flex flex-col items-center">
                    <div class="flex justify-between w-full mb-3 items-center">
                        <span id="lockStatusBadge" class="px-2.5 py-1 rounded-full text-[11px] sm:text-xs font-bold bg-blue-50 text-cat-darkblue flex items-center gap-1.5 border border-blue-100">
                            <span class="w-2 h-2 rounded-full bg-cat-lightblue animate-pulse"></span> Mode Spectateur (Live)
                        </span>
                        <button onclick="requestAccess('admin')" class="text-xs font-bold text-cat-darkblue hover:underline flex items-center gap-1">
                            <svg class="w-3.5 h-3.5 text-cat-yellow" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M5 9V7a5 5 0 0110 0v2a2 2 0 012 2v5a2 2 0 01-2 2H5a2 2 0 01-2-2v-5a2 2 0 012-2zm8-2v2H7V7a3 3 0 016 0z" clip-rule="evenodd"/></svg>
                            Administration
                        </button>
                    </div>

                    <div class="wheel-container">
                        <div class="wheel-pointer"></div>
                        <canvas id="wheelCanvas" width="500" height="500"></canvas>
                    </div>

                    <div class="mt-6 flex flex-wrap justify-center gap-3 w-full">
                        <button id="spinBtn" onclick="requestSpinWithPassword()" class="w-full sm:w-auto bg-cat-green hover:bg-emerald-700 disabled:opacity-50 text-white font-black px-6 sm:px-8 py-3.5 rounded-2xl shadow-lg transition text-base sm:text-lg flex items-center justify-center gap-2">
                            <svg class="w-5 h-5 text-amber-200 shrink-0" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M5 9V7a5 5 0 0110 0v2a2 2 0 012 2v5a2 2 0 01-2 2H5a2 2 0 01-2-2v-5a2 2 0 012-2zm8-2v2H7V7a3 3 0 016 0z" clip-rule="evenodd"/></svg>
                            LANCER LE TIRAGE (ADMIN)
                        </button>
                        <button id="resetWheelBtn" onclick="resetWheelStateRequest()" class="hidden w-full sm:w-auto bg-slate-200 hover:bg-slate-300 text-slate-700 font-bold px-4 py-3.5 rounded-2xl transition">
                            Réinitialiser
                        </button>
                    </div>
                </div>

                <div class="w-full lg:w-1/2 flex flex-col justify-center space-y-6">
                    <div class="bg-cat-lightbg border border-slate-200 rounded-2xl p-6 sm:p-8 text-center shadow-inner">
                        <h3 class="text-xs font-bold text-cat-gray uppercase tracking-widest mb-2">RÉSULTAT OFFICIEL DU TIRAGE</h3>
                        <div id="winnerDisplay" class="min-h-[120px] sm:min-h-[140px] flex flex-col items-center justify-center">
                            <p class="text-slate-500 font-medium text-sm">En attente du lancement par l'administrateur...</p>
                        </div>
                    </div>
                </div>

            </div>
        </section>

        <!-- ONGLET 2 : ADMINISTRATION -->
        <section id="tabAdmin" class="hidden space-y-6">
            <div class="bg-white p-4 sm:p-6 rounded-2xl shadow-sm border border-slate-200">
                <h2 class="text-base sm:text-lg font-bold text-cat-darkblue mb-1">Configuration de la Roue</h2>
                <p class="text-xs sm:text-sm text-cat-gray mb-4">Ajustez la taille du cercle central et du logo en pourcentage de la roue globale.</p>
                <div class="flex flex-col sm:flex-row items-start sm:items-center gap-4 bg-cat-lightbg p-4 rounded-xl border border-slate-200">
                    <div class="flex-1 w-full space-y-2">
                        <div class="flex justify-between text-xs font-bold text-slate-700">
                            <span>Taille du centre (Logo)</span>
                            <span id="centerSizeValDisplay">30%</span>
                        </div>
                        <input type="range" id="centerSizeRange" min="15" max="50" value="30" class="w-full accent-cat-lightblue cursor-pointer" oninput="updateCenterSizeDisplay(this.value)">
                    </div>
                    <button onclick="saveCenterSizeRequest()" class="w-full sm:w-auto bg-cat-darkblue hover:bg-blue-900 text-white font-bold px-5 py-2.5 rounded-xl text-sm shadow transition shrink-0">
                        Enregistrer
                    </button>
                </div>
            </div>

            <div class="bg-white p-4 sm:p-6 rounded-2xl shadow-sm border border-slate-200">
                <div class="flex flex-col lg:flex-row justify-between items-start lg:items-center gap-4 mb-6">
                    <div>
                        <h2 class="text-base sm:text-lg font-bold text-cat-darkblue">Gestion des Participants & Tickets</h2>
                        <p class="text-xs sm:text-sm text-cat-gray">Modifiez directement les numéros de tickets dans les champs.</p>
                    </div>
                    <div class="flex flex-wrap items-center gap-2 w-full lg:w-auto">
                        <button onclick="openAddModal()" class="flex-1 sm:flex-none bg-cat-green hover:bg-emerald-700 text-white font-bold px-4 py-2 rounded-xl text-xs sm:text-sm shadow transition text-center">
                            Ajouter
                        </button>
                        <button onclick="clearAllParticipantsRequest()" class="flex-1 sm:flex-none bg-red-50 hover:bg-red-100 text-red-600 font-semibold px-4 py-2 rounded-xl text-xs sm:text-sm transition border border-red-200 text-center">
                            Réinitialiser la liste
                        </button>
                        <button onclick="changePasswordPrompt()" class="w-full sm:w-auto bg-slate-100 hover:bg-slate-200 text-slate-700 font-semibold px-3 py-2 rounded-xl text-xs sm:text-sm transition text-center">
                            Changer MDP
                        </button>
                    </div>
                </div>

                <div class="overflow-x-auto rounded-xl border border-slate-200">
                    <table class="w-full text-left border-collapse text-xs sm:text-sm min-w-[600px]">
                        <thead>
                            <tr class="bg-cat-lightbg text-slate-700 font-bold border-b border-slate-200">
                                <th class="p-3">Nom & Prénom</th>
                                <th class="p-3">Statut</th>
                                <th class="p-3">Tickets attribués (Modifiables)</th>
                                <th class="p-3 text-right">Actions</th>
                            </tr>
                        </thead>
                        <tbody id="participantsTableBody" class="divide-y divide-slate-100"></tbody>
                    </table>
                </div>
                <div id="emptyState" class="hidden text-center py-12 text-slate-400 font-medium text-sm">
                    Aucun participant enregistré.
                </div>
            </div>
        </section>

        <!-- ONGLET 3 : HISTORIQUE -->
        <section id="tabHistory" class="hidden space-y-6">
            <div class="bg-white p-4 sm:p-6 rounded-2xl shadow-sm border border-slate-200">
                <div class="flex justify-between items-center mb-6">
                    <h2 class="text-base sm:text-lg font-bold text-cat-darkblue">Historique des Tirages</h2>
                    <button onclick="clearHistory()" class="text-red-600 hover:bg-red-50 font-semibold px-3 py-2 rounded-xl text-xs sm:text-sm transition">
                        Effacer
                    </button>
                </div>
                <div class="overflow-x-auto rounded-xl border border-slate-200">
                    <table class="w-full text-left border-collapse text-xs sm:text-sm min-w-[500px]">
                        <thead>
                            <tr class="bg-cat-lightbg text-slate-700 font-bold border-b border-slate-200">
                                <th class="p-3">Date & Heure</th>
                                <th class="p-3">Ticket Gagnant</th>
                                <th class="p-3">Participant Gagnant</th>
                                <th class="p-3 text-right">Actions</th>
                            </tr>
                        </thead>
                        <tbody id="historyTableBody" class="divide-y divide-slate-100"></tbody>
                    </table>
                </div>
            </div>
        </section>

    </main>

    <!-- Modal Mot de Passe -->
    <div id="passwordModal" class="hidden fixed inset-0 bg-slate-900/60 backdrop-blur-sm z-50 flex items-center justify-center p-4">
        <div class="bg-white rounded-2xl shadow-2xl max-w-sm w-full p-6 space-y-4 border border-slate-200">
            <div class="text-center space-y-2">
                <h3 id="passwordModalTitle" class="text-lg font-bold text-cat-darkblue">Accès Protégé</h3>
                <p id="passwordModalDesc" class="text-xs text-cat-gray">Saisissez le mot de passe administrateur.</p>
            </div>
            <form onsubmit="verifyPassword(event)" class="space-y-4">
                <div>
                    <input type="password" id="adminPasswordInput" placeholder="Mot de passe" required class="w-full px-4 py-2.5 rounded-xl border border-slate-300 focus:outline-none focus:border-cat-lightblue text-center text-sm font-bold tracking-widest">
                </div>
                <p id="passwordError" class="hidden text-xs text-red-600 font-bold text-center">Mot de passe incorrect.</p>
                <div class="flex gap-2">
                    <button type="button" onclick="closePasswordModal()" class="w-1/2 py-2.5 rounded-xl text-xs font-bold text-slate-500 bg-slate-100 hover:bg-slate-200 transition">Annuler</button>
                    <button type="submit" class="w-1/2 bg-cat-darkblue hover:bg-blue-900 text-white py-2.5 rounded-xl text-xs font-bold shadow transition">Valider</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Modal Participant -->
    <div id="participantModal" class="hidden fixed inset-0 bg-slate-900/50 backdrop-blur-sm z-50 flex items-center justify-center p-4">
        <div class="bg-white rounded-2xl shadow-2xl max-w-md w-full p-6 space-y-4 border border-slate-200">
            <h3 id="modalTitle" class="text-lg font-bold text-cat-darkblue">Ajouter un participant</h3>
            <form id="participantForm" onsubmit="saveParticipantModal(event)" class="space-y-4">
                <input type="hidden" id="editIndex" value="-1">
                <div>
                    <label class="block text-xs font-bold text-cat-gray uppercase mb-1">Nom</label>
                    <input type="text" id="partNom" required class="w-full px-3 py-2 rounded-xl border border-slate-300 text-sm focus:outline-none focus:border-cat-lightblue">
                </div>
                <div>
                    <label class="block text-xs font-bold text-cat-gray uppercase mb-1">Prénom</label>
                    <input type="text" id="partPrenom" required class="w-full px-3 py-2 rounded-xl border border-slate-300 text-sm focus:outline-none focus:border-cat-lightblue">
                </div>
                <div>
                    <label class="block text-xs font-bold text-cat-gray uppercase mb-1">Statut</label>
                    <select id="partStatut" class="w-full px-3 py-2 rounded-xl border border-slate-300 text-sm bg-white focus:outline-none focus:border-cat-lightblue">
                        <option value="adherent">Adhérent (2 numéros)</option>
                        <option value="non_adherent">Non-adhérent (1 numéro)</option>
                    </select>
                </div>
                <div class="flex justify-end gap-3 pt-2">
                    <button type="button" onclick="closeAddModal()" class="px-4 py-2 rounded-xl text-sm font-semibold text-slate-600 hover:bg-slate-100 transition">Annuler</button>
                    <button type="submit" class="bg-cat-green hover:bg-emerald-700 text-white px-4 py-2 rounded-xl text-sm font-bold shadow transition">Enregistrer</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        let participants = [];
        let history = [];
        let wheelCenterPercent = 30;
        let isAuthenticated = false;
        let cachedAdminPassword = '';
        let pendingAction = null;
        let isSpinning = false;
        let currentRotation = 0;
        let lastLiveTimestamp = null;
        let lastDataHash = '';
        let pendingHistoryIdToDelete = null;

        const centerLogo = new Image();
        centerLogo.onload = function() {
            drawWheel();
        };
        centerLogo.src = 'Logo_CAT.png';

        const wheelColors = ['#003B8E', '#1FA84A', '#1D8FE1', '#F59E0B', '#5B6470', '#2563EB', '#16A34A', '#D97706'];

        window.addEventListener('DOMContentLoaded', () => {
            calculateWorkingDay();
            fetchAppData();
            setInterval(fetchAppData, 1500);
        });

        function fetchAppData() {
            if (document.activeElement && document.activeElement.tagName === 'INPUT' && (document.activeElement.type === 'number' || document.activeElement.type === 'range')) {
                return; 
            }

            fetch('?action=get_data')
                .then(res => res.json())
                .then(data => {
                    let newDataHash = JSON.stringify(data.participants) + JSON.stringify(data.history) + data.wheel_center_size;
                    
                    participants = data.participants || [];
                    history = data.history || [];
                    wheelCenterPercent = data.wheel_center_size || 30;

                    const rangeEl = document.getElementById('centerSizeRange');
                    if (rangeEl && document.activeElement !== rangeEl) {
                        rangeEl.value = wheelCenterPercent;
                        updateCenterSizeDisplay(wheelCenterPercent);
                    }

                    if (newDataHash !== lastDataHash) {
                        lastDataHash = newDataHash;
                        renderParticipantsTable();
                        renderHistoryTable();
                        drawWheel();
                    }

                    if (data.active_spin) {
                        let serverTimestamp = data.active_spin.timestamp;
                        let winningTicket = data.active_spin.ticketNum;

                        if (serverTimestamp !== lastLiveTimestamp) {
                            lastLiveTimestamp = serverTimestamp;
                            if (!isSpinning) {
                                triggerLiveSpinAnimation(winningTicket);
                            }
                        }
                    } else {
                        if (lastLiveTimestamp !== null && !isSpinning) {
                            resetWheelStateUI();
                        }
                    }
                })
                .catch(err => console.error("Erreur de synchronisation :", err));
        }

        function updateCenterSizeDisplay(val) {
            const display = document.getElementById('centerSizeValDisplay');
            if (display) display.textContent = val + '%';
        }

        function saveCenterSizeRequest() {
            if (!isAuthenticated) {
                pendingAction = 'save_size';
                document.getElementById('passwordModalTitle').textContent = "Confirmation requise";
                document.getElementById('passwordModalDesc').textContent = "Saisissez le mot de passe administrateur pour enregistrer cette configuration.";
                document.getElementById('adminPasswordInput').value = '';
                document.getElementById('passwordError').classList.add('hidden');
                document.getElementById('passwordModal').classList.remove('hidden');
                return;
            }

            const rangeVal = document.getElementById('centerSizeRange').value;
            fetch('?action=save_center_size', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ password: cachedAdminPassword, size: rangeVal })
            })
            .then(res => res.json())
            .then(data => {
                if (data.success) {
                    wheelCenterPercent = parseInt(rangeVal);
                    lastDataHash = '';
                    fetchAppData();
                    alert("Taille du centre de la roue mise à jour avec succès !");
                } else {
                    alert("Erreur lors de la sauvegarde.");
                }
            });
        }

        function clearAllParticipantsRequest() {
            if (!confirm("Attention : Voulez-vous vraiment réinitialiser la liste et supprimer TOUS les participants ?")) return;

            if (!isAuthenticated) {
                pendingAction = 'clear_all';
                document.getElementById('passwordModalTitle').textContent = "Confirmation requise";
                document.getElementById('passwordModalDesc').textContent = "Saisissez le mot de passe administrateur pour réinitialiser la liste.";
                document.getElementById('adminPasswordInput').value = '';
                document.getElementById('passwordError').classList.add('hidden');
                document.getElementById('passwordModal').classList.remove('hidden');
                return;
            }

            executeClearAllParticipants();
        }

        function executeClearAllParticipants() {
            fetch('?action=clear_all_participants', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ password: cachedAdminPassword })
            })
            .then(res => res.json())
            .then(data => {
                if (data.success) {
                    participants = [];
                    lastDataHash = '';
                    fetchAppData();
                    alert("La liste des participants a été réinitialisée.");
                } else {
                    alert("Erreur lors de la réinitialisation.");
                }
            });
        }

        function requestAccess(target) {
            if (target === 'draw' || isAuthenticated) {
                switchTab(target);
            } else {
                pendingAction = target === 'admin' ? 'tab_admin' : 'tab_history';
                document.getElementById('passwordModalTitle').textContent = "Accès Protégé";
                document.getElementById('passwordModalDesc').textContent = "Saisissez le mot de passe administrateur.";
                document.getElementById('adminPasswordInput').value = '';
                document.getElementById('passwordError').classList.add('hidden');
                document.getElementById('passwordModal').classList.remove('hidden');
            }
        }

        function requestSpinWithPassword() {
            if (isAuthenticated) {
                startSpinProcess();
            } else {
                pendingAction = 'spin';
                document.getElementById('passwordModalTitle').textContent = "Lancement du Tirage";
                document.getElementById('passwordModalDesc').textContent = "Saisissez le mot de passe administrateur pour lancer la roue.";
                document.getElementById('adminPasswordInput').value = '';
                document.getElementById('passwordError').classList.add('hidden');
                document.getElementById('passwordModal').classList.remove('hidden');
            }
        }

        function verifyPassword(event) {
            event.preventDefault();
            const inputVal = document.getElementById('adminPasswordInput').value;
            
            fetch('?action=verify_password', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ password: inputVal })
            })
            .then(res => res.json())
            .then(data => {
                if (data.success) {
                    isAuthenticated = true;
                    cachedAdminPassword = inputVal;
                    closePasswordModal();
                    if (pendingAction === 'tab_admin') switchTab('admin');
                    else if (pendingAction === 'tab_history') switchTab('history');
                    else if (pendingAction === 'spin') startSpinProcess();
                    else if (pendingAction === 'save_size') saveCenterSizeRequest();
                    else if (pendingAction === 'clear_all') executeClearAllParticipants();
                    else if (pendingAction === 'delete_history_item') executeDeleteHistoryItem(pendingHistoryIdToDelete);
                } else {
                    document.getElementById('passwordError').classList.remove('hidden');
                }
            });
        }

        function closePasswordModal() {
            document.getElementById('passwordModal').classList.add('hidden');
            pendingAction = null;
        }

        function changePasswordPrompt() {
            const currentPass = prompt("Veuillez saisir le mot de passe actuel :");
            if (currentPass) {
                const newPass = prompt("Saisissez le nouveau mot de passe :");
                if (newPass && newPass.trim() !== "") {
                    fetch('?action=change_password', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({ old_password: currentPass, new_password: newPass.trim() })
                    })
                    .then(res => res.json())
                    .then(data => {
                        if (data.success) {
                            cachedAdminPassword = newPass.trim();
                            alert("Mot de passe mis à jour avec succès.");
                        } else {
                            alert("Mot de passe actuel incorrect.");
                        }
                    });
                }
            }
        }

        function switchTab(tabId) {
            ['admin', 'draw', 'history'].forEach(t => {
                const el = document.getElementById(`tab${t.charAt(0).toUpperCase() + t.slice(1)}`);
                const btn = document.getElementById(`tab${t.charAt(0).toUpperCase() + t.slice(1)}Btn`);
                if (el) el.classList.add('hidden');
                if (btn) {
                    btn.classList.remove('bg-cat-lightblue', 'text-white', 'shadow');
                    btn.classList.add('text-slate-300');
                }
            });
            const targetEl = document.getElementById(`tab${tabId.charAt(0).toUpperCase() + tabId.slice(1)}`);
            const targetBtn = document.getElementById(`tab${tabId.charAt(0).toUpperCase() + tabId.slice(1)}Btn`);
            if (targetEl) targetEl.classList.remove('hidden');
            if (targetBtn) {
                targetBtn.classList.remove('text-slate-300');
                targetBtn.classList.add('bg-cat-lightblue', 'text-white', 'shadow');
            }
            if (tabId === 'draw') drawWheel();
        }

        function calculateWorkingDay() {
            try {
                const now = new Date();
                const year = now.getFullYear();
                const month = now.getMonth();
                let date = new Date(year, month + 1, 0);
                while (date.getDay() === 0 || date.getDay() === 6) {
                    date.setDate(date.getDate() - 1);
                }
                const formattedDate = date.toLocaleDateString('fr-FR', { weekday: 'long', year: 'numeric', month: 'long', day: 'numeric' });
                const isToday = (now.toDateString() === date.toDateString());
                const text = document.getElementById('workingDayText');
                if (text) text.textContent = `Prochain CAT'Boost : ${formattedDate}` + (isToday ? " (AUJOURD'HUI !)" : "");
            } catch (e) {
                const text = document.getElementById('workingDayText');
                if (text) text.textContent = "Date du tirage indisponible";
            }
        }

        function generateUniqueTicketNumber() {
            let existingTickets = new Set();
            participants.forEach(p => {
                if (p.tickets) p.tickets.forEach(t => existingTickets.add(Number(t)));
            });
            let num = Math.floor(1000 + Math.random() * 9000);
            while (existingTickets.has(num)) {
                num = Math.floor(1000 + Math.random() * 9000);
            }
            return num;
        }

        function openAddModal(index = -1) {
            document.getElementById('participantModal').classList.remove('hidden');
            if (index >= 0) {
                document.getElementById('modalTitle').textContent = "Modifier le participant";
                document.getElementById('editIndex').value = index;
                document.getElementById('partNom').value = participants[index].nom;
                document.getElementById('partPrenom').value = participants[index].prenom;
                document.getElementById('partStatut').value = participants[index].statut;
            } else {
                document.getElementById('modalTitle').textContent = "Ajouter un participant";
                document.getElementById('editIndex').value = -1;
                document.getElementById('participantForm').reset();
            }
        }

        function closeAddModal() {
            document.getElementById('participantModal').classList.add('hidden');
        }

        function saveParticipantModal(event) {
            event.preventDefault();
            const index = parseInt(document.getElementById('editIndex').value);
            const nom = document.getElementById('partNom').value.trim();
            const prenom = document.getElementById('partPrenom').value.trim();
            const statut = document.getElementById('partStatut').value;
            const targetCount = (statut === 'adherent') ? 2 : 1;

            if (index >= 0) {
                let p = participants[index];
                p.nom = nom;
                p.prenom = prenom;
                if (p.statut !== statut) {
                    p.statut = statut;
                    p.tickets = [];
                    for (let i = 0; i < targetCount; i++) p.tickets.push(generateUniqueTicketNumber());
                }
            } else {
                let tickets = [];
                for (let i = 0; i < targetCount; i++) tickets.push(generateUniqueTicketNumber());
                participants.push({ nom, prenom, statut, tickets });
            }

            syncParticipantsToServer();
            closeAddModal();
        }

        function deleteParticipant(index) {
            if (confirm("Voulez-vous supprimer ce participant ?")) {
                participants.splice(index, 1);
                syncParticipantsToServer();
            }
        }

        function updateTicketNumber(partIndex, ticketIndex, newval) {
            let val = parseInt(newval);
            if (isNaN(val) || val <= 0) {
                alert("Veuillez saisir un numéro valide.");
                renderParticipantsTable();
                return;
            }

            let isDuplicate = false;
            participants.forEach((p, pIdx) => {
                p.tickets.forEach((t, tIdx) => {
                    if (pIdx !== partIndex || tIdx !== ticketIndex) {
                        if (Number(t) === val) isDuplicate = true;
                    }
                });
            });

            if (isDuplicate) {
                alert(`Le numéro ${val} est déjà attribué à un autre ticket !`);
                renderParticipantsTable();
                return;
            }

            participants[partIndex].tickets[ticketIndex] = val;
            syncParticipantsToServer();
        }

        function syncParticipantsToServer() {
            fetch('?action=save_participants', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ password: cachedAdminPassword, participants: participants })
            })
            .then(res => res.json())
            .then(data => {
                if (data.success) {
                    lastDataHash = ''; 
                    fetchAppData();
                } else {
                    alert("Mot de passe administrateur requis pour modifier les données.");
                    requestAccess('admin');
                }
            });
        }

        function renderParticipantsTable() {
            const tbody = document.getElementById('participantsTableBody');
            const emptyState = document.getElementById('emptyState');
            if (!tbody) return;
            tbody.innerHTML = '';

            if (participants.length === 0) {
                if (emptyState) emptyState.classList.remove('hidden');
                return;
            }
            if (emptyState) emptyState.classList.add('hidden');

            participants.forEach((p, pIndex) => {
                let ticketsHtml = p.tickets.map((t, tIndex) => `
                    <span class="inline-flex items-center gap-1 bg-sky-50 text-cat-darkblue border border-sky-200 px-2 py-0.5 rounded-lg text-xs font-bold mr-1 mb-1">
                        N° 
                        <input type="number" value="${t}" onchange="updateTicketNumber(${pIndex}, ${tIndex}, this.value)" class="w-14 bg-white border border-slate-300 rounded px-1 py-0.5 text-xs text-center font-bold focus:outline-none focus:border-cat-lightblue">
                    </span>
                `).join('');

                const tr = document.createElement('tr');
                tr.className = "border-b border-slate-100 hover:bg-slate-50 transition";
                tr.innerHTML = `
                    <td class="p-3 font-semibold text-slate-800">${p.nom} ${p.prenom}</td>
                    <td class="p-3">
                        <span class="px-2 py-1 rounded-full text-[11px] sm:text-xs font-bold ${p.statut === 'adherent' ? 'bg-emerald-50 text-cat-green border border-emerald-200' : 'bg-slate-100 text-slate-700'}">
                            ${p.statut === 'adherent' ? 'Adhérent (2)' : 'Non-adhérent (1)'}
                        </span>
                    </td>
                    <td class="p-3">${ticketsHtml}</td>
                    <td class="p-3 text-right space-x-1 sm:space-x-2">
                        <button onclick="openAddModal(${pIndex})" class="text-cat-lightblue hover:text-blue-700 font-semibold text-xs bg-sky-50 hover:bg-sky-100 px-2.5 py-1.5 rounded-lg transition">Éditer</button>
                        <button onclick="deleteParticipant(${pIndex})" class="text-red-600 hover:text-red-900 font-semibold text-xs bg-red-50 hover:bg-red-100 px-2.5 py-1.5 rounded-lg transition">Supprimer</button>
                    </td>
                `;
                tbody.appendChild(tr);
            });
        }

        function getAllActiveTickets() {
            let list = [];
            participants.forEach(p => {
                if (p.tickets) p.tickets.forEach(ticketNum => list.push({ ticketNum }));
            });
            return list;
        }

        function drawWheel() {
            const canvas = document.getElementById('wheelCanvas');
            if (!canvas) return;
            const ctx = canvas.getContext('2d');
            const items = getAllActiveTickets();
            const width = canvas.width, height = canvas.height;
            const centerX = width / 2, centerY = height / 2, radius = width / 2 - 10;

            ctx.clearRect(0, 0, width, height);
            if (items.length === 0) {
                ctx.fillStyle = '#94A3B8';
                ctx.font = 'bold 15px sans-serif';
                ctx.textAlign = 'center';
                ctx.textBaseline = 'middle';
                ctx.fillText("Aucun participant configuré", centerX, centerY);
                return;
            }

            const arcSize = (2 * Math.PI) / items.length;
            items.forEach((item, i) => {
                const angle = currentRotation + i * arcSize;
                ctx.beginPath();
                ctx.fillStyle = wheelColors[i % wheelColors.length];
                ctx.moveTo(centerX, centerY);
                ctx.arc(centerX, centerY, radius, angle, angle + arcSize);
                ctx.lineTo(centerX, centerY);
                ctx.fill();
                ctx.strokeStyle = '#FFFFFF';
                ctx.lineWidth = 2;
                ctx.stroke();

                ctx.save();
                ctx.translate(centerX, centerY);
                ctx.rotate(angle + arcSize / 2);
                ctx.textAlign = 'right';
                ctx.fillStyle = '#FFFFFF';
                ctx.font = 'bold 13px sans-serif';
                ctx.fillText(`N° ${item.ticketNum}`, radius - 22, 4);
                ctx.restore();
            });

            const centerRadius = radius * (wheelCenterPercent / 100);

            ctx.beginPath();
            ctx.arc(centerX, centerY, centerRadius, 0, 2 * Math.PI);
            ctx.fillStyle = '#FFFFFF';
            ctx.shadowColor = 'rgba(0, 59, 142, 0.25)';
            ctx.shadowBlur = 15;
            ctx.fill();
            ctx.shadowBlur = 0;
            ctx.strokeStyle = '#003B8E';
            ctx.lineWidth = 4;
            ctx.stroke();

            if (centerLogo.complete && centerLogo.naturalWidth !== 0) {
                ctx.save();
                ctx.beginPath();
                ctx.arc(centerX, centerY, centerRadius - 3, 0, 2 * Math.PI);
                ctx.clip();
                const logoSize = centerRadius * 1.8;
                ctx.drawImage(centerLogo, centerX - (logoSize / 2), centerY - (logoSize / 2), logoSize, logoSize);
                ctx.restore();
            }
        }

        function startSpinProcess() {
            const items = getAllActiveTickets();
            if (items.length === 0) {
                alert("Veuillez ajouter des participants avant de lancer le tirage.");
                return;
            }

            const winningIndex = Math.floor(Math.random() * items.length);
            const winningTicket = items[winningIndex].ticketNum;

            fetch('?action=record_winner', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ ticketNum: winningTicket })
            })
            .then(res => res.json())
            .then(data => {
                if (data.success) {
                    triggerLiveSpinAnimation(winningTicket);
                }
            });
        }

        function triggerLiveSpinAnimation(targetTicketNum) {
            const items = getAllActiveTickets();
            if (items.length === 0) return;

            let winningIndex = items.findIndex(i => Number(i.ticketNum) === Number(targetTicketNum));
            if (winningIndex === -1) winningIndex = 0;

            isSpinning = true;
            const spinBtn = document.getElementById('spinBtn');
            const resetBtn = document.getElementById('resetWheelBtn');
            if (spinBtn) spinBtn.disabled = true;
            if (resetBtn) resetBtn.classList.remove('hidden');

            const arcSize = (2 * Math.PI) / items.length;
            const targetAngle = 3 * Math.PI / 2 - (winningIndex * arcSize + arcSize / 2);
            const fullSpins = 5 * 2 * Math.PI;
            const totalRotation = currentRotation + fullSpins + (targetAngle - (currentRotation % (2 * Math.PI)));

            const startTime = performance.now();
            const duration = 4000;

            function animate(currentTime) {
                const elapsed = currentTime - startTime;
                const progress = Math.min(elapsed / duration, 1);
                const easeOut = 1 - Math.pow(1 - progress, 3);

                currentRotation = currentRotation + (totalRotation - currentRotation) * easeOut;
                drawWheel();

                if (progress < 1) {
                    requestAnimationFrame(animate);
                } else {
                    isSpinning = false;
                    currentRotation = totalRotation % (2 * Math.PI);
                    displayWinnerUI(targetTicketNum);
                }
            }
            requestAnimationFrame(animate);
        }

        function displayWinnerUI(ticketNum) {
            const winnerDisplay = document.getElementById('winnerDisplay');
            if (winnerDisplay) {
                winnerDisplay.innerHTML = `
                    <div class="animate-bounce">
                        <span class="text-[11px] sm:text-xs font-extrabold uppercase bg-cat-green text-white px-3 py-1 rounded-full shadow">TICKET GAGNANT</span>
                        <h2 class="text-2xl sm:text-3xl font-black text-cat-darkblue mt-2">N° ${ticketNum}</h2>
                        <p class="text-xs text-cat-gray mt-1">Tirage validé en direct live</p>
                    </div>
                `;
            }
            if (typeof confetti === 'function') {
                confetti({ particleCount: 120, spread: 70, origin: { y: 0.6 } });
            }
        }

        function resetWheelStateRequest() {
            fetch('?action=reset_spin', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ password: cachedAdminPassword })
            })
            .then(res => res.json())
            .then(data => {
                if (data.success) {
                    resetWheelStateUI();
                } else {
                    alert("Mot de passe administrateur requis pour réinitialiser.");
                    requestAccess('admin');
                }
            });
        }

        function resetWheelStateUI() {
            lastLiveTimestamp = null;
            const spinBtn = document.getElementById('spinBtn');
            const resetBtn = document.getElementById('resetWheelBtn');
            if (spinBtn) spinBtn.disabled = false;
            if (resetBtn) resetBtn.classList.add('hidden');
            const winnerDisplay = document.getElementById('winnerDisplay');
            if (winnerDisplay) {
                winnerDisplay.innerHTML = `<p class="text-slate-500 font-medium text-sm">En attente du lancement par l'administrateur...</p>`;
            }
            drawWheel();
        }

        function renderHistoryTable() {
            const tbody = document.getElementById('historyTableBody');
            if (!tbody) return;
            tbody.innerHTML = '';
            
            if (history.length === 0) return;

            history.forEach(item => {
                const tr = document.createElement('tr');
                tr.className = "border-b border-slate-100 hover:bg-slate-50 transition";
                tr.innerHTML = `
                    <td class="p-3 text-slate-600 font-medium">${item.date}</td>
                    <td class="p-3 font-black text-cat-darkblue text-sm sm:text-base">N° ${item.ticketNum}</td>
                    <td class="p-3 font-semibold text-slate-700">${item.statut}</td>
                    <td class="p-3 text-right">
                        <button onclick="deleteHistoryItem(${item.id})" class="text-red-600 hover:text-red-900 font-semibold text-xs bg-red-50 hover:bg-red-100 px-2.5 py-1.5 rounded-lg transition">Supprimer</button>
                    </td>
                `;
                tbody.appendChild(tr);
            });
        }

        function clearHistory() {
            if (confirm("Effacer tout l'historique ?")) {
                fetch('?action=clear_history', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ password: cachedAdminPassword })
                })
                .then(res => res.json())
                .then(data => {
                    if (data.success) {
                        lastDataHash = '';
                        fetchAppData();
                    } else {
                        alert("Mot de passe administrateur requis.");
                        requestAccess('admin');
                    }
                });
            }
        }

        function deleteHistoryItem(id) {
            if (!confirm("Voulez-vous supprimer cette ligne de l'historique ?")) return;

            if (!isAuthenticated) {
                pendingAction = 'delete_history_item';
                pendingHistoryIdToDelete = id;
                document.getElementById('passwordModalTitle').textContent = "Confirmation requise";
                document.getElementById('passwordModalDesc').textContent = "Saisissez le mot de passe administrateur pour supprimer cet élément.";
                document.getElementById('adminPasswordInput').value = '';
                document.getElementById('passwordError').classList.add('hidden');
                document.getElementById('passwordModal').classList.remove('hidden');
                return;
            }

            executeDeleteHistoryItem(id);
        }

        function executeDeleteHistoryItem(id) {
            fetch('?action=delete_history_item', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ password: cachedAdminPassword, id: id })
            })
            .then(res => res.json())
            .then(data => {
                if (data.success) {
                    lastDataHash = '';
                    fetchAppData();
                } else {
                    alert("Erreur ou mot de passe administrateur requis.");
                    requestAccess('admin');
                }
            });
        }
    </script>
</body>
</html>