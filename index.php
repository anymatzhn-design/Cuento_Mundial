<?php
// ============================================================
//  CUENTO MUNDIAL — Periódico del Mundial 2026 (PREMIUM EDITION)
//  + Integración de YouTube + Seguridad Bcrypt
// ============================================================

define('DATA_DIR',      __DIR__ . '/data/');
define('UPLOAD_DIR',    __DIR__ . '/uploads/');
define('ARTICLES_FILE', DATA_DIR . 'articles.json');

// ============================================================
// 🔐 ZONA DE SEGURIDAD (SYSTEM ACCESS)
// ============================================================
// Deja este campo vacío ('') la primera vez. 
// Ve a tu web, entra al Admin, escribe tu contraseña deseada y el 
// sistema te generará el Hash seguro para que lo pegues aquí.
define('ADMIN_PASS_HASH', '$2y$10$Tt309FfAYvQ31pjwVdnQBebGY/0ktSRunlhrbyLk4vRptVlxBtLSy'); 


foreach ([DATA_DIR, UPLOAD_DIR] as $dir)
    if (!is_dir($dir)) mkdir($dir, 0755, true);
if (!file_exists(ARTICLES_FILE))
    file_put_contents(ARTICLES_FILE, json_encode([], JSON_PRETTY_PRINT));

session_start();

function loadArticles(): array {
    $d = json_decode(file_get_contents(ARTICLES_FILE), true);
    return is_array($d) ? $d : [];
}
function saveArticles(array $a): void {
    file_put_contents(ARTICLES_FILE, json_encode($a, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
}
function sanitize(string $s): string {
    return htmlspecialchars(trim($s), ENT_QUOTES, 'UTF-8');
}
function timeAgo(int $ts): string {
    $d = time() - $ts;
    if ($d < 60)    return 'Ahora mismo';
    if ($d < 3600)  return floor($d/60) . ' min';
    if ($d < 86400) return floor($d/3600) . 'h';
    return date('d/m/Y', $ts);
}
function uploadFile(array $f, string $pfx): string {
    $ok = ['image/jpeg','image/png','image/gif','image/webp',
           'video/mp4','video/webm','video/ogg','video/quicktime'];
    if (!in_array(mime_content_type($f['tmp_name']), $ok)) return '';
    $ext  = strtolower(pathinfo($f['name'], PATHINFO_EXTENSION));
    $name = $pfx . '_' . time() . '_' . rand(1000,9999) . '.' . $ext;
    move_uploaded_file($f['tmp_name'], UPLOAD_DIR . $name);
    return $name;
}
function isVideo(string $f): bool {
    return in_array(strtolower(pathinfo($f, PATHINFO_EXTENSION)), ['mp4','webm','ogg','mov']);
}

function extractYoutubeId(string $url): string {
    if (empty($url)) return '';
    preg_match('%(?:youtube(?:-nocookie)?\.com/(?:[^/]+/.+/|(?:v|e(?:mbed)?)/|.*[?&]v=)|youtu\.be/)([^"&?/\s]{11})%i', $url, $match);
    return $match[1] ?? '';
}

function renderCardMedia(array $a, bool $isHero = false): string {
    $html = '';
    if (!empty($a['youtube'])) {
        $thumb = "https://img.youtube.com/vi/" . $a['youtube'] . "/maxresdefault.jpg";
        $html .= '<img src="' . $thumb . '" alt="Video Thumbnail">';
        if (!$isHero) $html .= '<div class="vid-pill">▶ YouTube</div>';
    } elseif (!empty($a['media'])) {
        if (isVideo($a['media'])) {
            $attrs = $isHero ? 'autoplay muted loop playsinline' : 'muted';
            $html .= '<video ' . $attrs . '><source src="uploads/' . htmlspecialchars($a['media']) . '"></video>';
            if (!$isHero) $html .= '<div class="vid-pill">▶ Video</div>';
        } else {
            $html .= '<img src="uploads/' . htmlspecialchars($a['media']) . '" alt="">';
        }
    } else {
        $html .= '<div style="width:100%;height:100%;background:#0c1830;"></div>';
    }
    return $html;
}

$SECTIONS = [
    'portada'     => ['label'=>'Portada',           'icon'=>'🌐'],
    'rumbo26'     => ['label'=>'Rumbo al Mundial',  'icon'=>'🏆'],
    'selecciones' => ['label'=>'Selecciones',       'icon'=>'🎽'],
    'estrellas'   => ['label'=>'Estrellas',         'icon'=>'⭐'],
    'estadios'    => ['label'=>'Estadios 2026',     'icon'=>'🏟️'],
    'pronosticos' => ['label'=>'Pronósticos',       'icon'=>'📊'],
    'opinion'     => ['label'=>'Opinión',           'icon'=>'✍️'],
    'videos'      => ['label'=>'Videos',            'icon'=>'🎬'],
];

$isAdmin   = !empty($_SESSION['admin']);
$adminArea = isset($_GET['admin']);
$setupMode = false;
$newHash   = '';

if ($adminArea) {
    if (isset($_POST['login_pass'])) {
        if (empty(ADMIN_PASS_HASH)) {
            // Genera el hash de seguridad automáticamente la primera vez
            $newHash = password_hash($_POST['login_pass'], PASSWORD_BCRYPT);
            $setupMode = true;
        } else {
            // Verifica la contraseña contra el hash de alta seguridad
            if (password_verify($_POST['login_pass'], ADMIN_PASS_HASH)) {
                $_SESSION['admin'] = true; 
                $isAdmin = true;
            } else {
                $loginError = 'Acceso denegado. Credenciales inválidas.';
            }
        }
    }
    if (isset($_GET['logout'])) { session_destroy(); header('Location: ?'); exit; }
    
    if ($isAdmin && isset($_POST['save_article'])) {
        $arts  = loadArticles();
        $media = '';
        if (!empty($_FILES['media']['name'])) $media = uploadFile($_FILES['media'], 'media');
        $ytId = extractYoutubeId($_POST['youtube'] ?? '');

        if (!empty($_POST['edit_id'])) {
            foreach ($arts as &$a) {
                if ($a['id'] === $_POST['edit_id']) {
                    $a['title']    = sanitize($_POST['title']);
                    $a['subtitle'] = sanitize($_POST['subtitle']);
                    $a['section']  = sanitize($_POST['section']);
                    $a['body']     = strip_tags($_POST['body'],'<p><b><i><ul><li><ol><br><strong><em><h2><h3><blockquote>');
                    $a['author']   = sanitize($_POST['author']);
                    $a['youtube']  = $ytId; 
                    $a['featured'] = isset($_POST['featured']) ? 1 : 0;
                    $a['updated']  = time();
                    if ($media) $a['media'] = $media;
                }
            }
        } else {
            $arts[] = [
                'id'=>uniqid('art_',true), 'title'=>sanitize($_POST['title']),
                'subtitle'=>sanitize($_POST['subtitle']), 'section'=>sanitize($_POST['section']),
                'body'=>strip_tags($_POST['body'],'<p><b><i><ul><li><ol><br><strong><em><h2><h3><blockquote>'),
                'author'=>sanitize($_POST['author']), 'media'=>$media, 'youtube'=>$ytId,
                'featured'=>isset($_POST['featured'])?1:0, 'created'=>time(), 'updated'=>time(),
            ];
        }
        saveArticles($arts);
        header('Location: ?admin'); exit;
    }
    if ($isAdmin && isset($_GET['delete'])) {
        saveArticles(array_values(array_filter(loadArticles(), fn($a)=>$a['id']!=$_GET['delete'])));
        header('Location: ?admin'); exit;
    }
}

$articles = loadArticles();
usort($articles, fn($a,$b)=>$b['created']-$a['created']);
$activeSection   = (isset($_GET['s']) && array_key_exists($_GET['s'],$SECTIONS)) ? $_GET['s'] : 'portada';
$activeArticleId = $_GET['art'] ?? null;
$editArticle     = null;
if ($adminArea && $isAdmin && isset($_GET['edit']))
    foreach ($articles as $a) if ($a['id']===$_GET['edit']) { $editArticle=$a; break; }
$singleArticle = null;
if ($activeArticleId)
    foreach ($articles as $a) if ($a['id']===$activeArticleId) { $singleArticle=$a; break; }
$sectionArt = $activeSection==='portada' ? $articles
    : array_values(array_filter($articles,fn($a)=>$a['section']===$activeSection));

$dias  = ['Domingo','Lunes','Martes','Miércoles','Jueves','Viernes','Sábado'];
$meses = ['Ene','Feb','Mar','Abr','May','Jun','Jul','Ago','Sep','Oct','Nov','Dic'];
$hoy   = $dias[date('w')].', '.date('j').' '.$meses[date('n')-1].' '.date('Y');
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>CUENTO MUNDIAL | El Fútbol se vive aquí</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Archivo+Black&family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
<style>
/* ========================================================
   STACK & TOKENS (Premium Streaming UI)
   ======================================================== */
:root {
  --bg-dark: #040914; --bg-surface: #0a1326; --bg-surface-hover: #0e1c36;
  --mint: #00D4E0; --mint-glow: rgba(0, 212, 224, 0.3); --accent-blue: #1C4ED8;
  --text-pure: #FFFFFF; --text-muted: #8B9BB4; --text-dark: #040914;
  --border-subtle: rgba(255, 255, 255, 0.08); --border-mint: rgba(0, 212, 224, 0.2);
  --font-display: 'Archivo Black', sans-serif; --font-body: 'Inter', sans-serif;
}

*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
html { scroll-behavior: smooth; }
body { font-family: var(--font-body); background-color: var(--bg-dark); color: var(--text-pure); overflow-x: hidden; -webkit-font-smoothing: antialiased; }
body::before { content: ''; position: fixed; top: -20vh; left: 50%; transform: translateX(-50%); width: 80vw; height: 60vh; background: radial-gradient(ellipse at center, rgba(0, 212, 224, 0.08) 0%, transparent 70%); pointer-events: none; z-index: -1; }
a { color: inherit; text-decoration: none; }
img, video { max-width: 100%; display: block; border-radius: 8px; }

.alert-strip { background: var(--mint); color: var(--text-dark); font-size: 0.75rem; font-weight: 800; letter-spacing: 0.1em; text-transform: uppercase; padding: 10px 0; overflow: hidden; position: relative; }
.alert-ticker { display: inline-block; white-space: nowrap; animation: ticker 35s linear infinite; }
.alert-ticker span { margin: 0 40px; }
.alert-ticker span::before { content: '• '; font-weight: 900; }
@keyframes ticker { from { transform: translateX(100vw); } to { transform: translateX(-100%); } }

.masthead { padding: 60px 20px 40px; text-align: center; position: relative; }
.mh-bar { position: absolute; top: 15px; left: 30px; right: 30px; display: flex; justify-content: space-between; font-size: 0.75rem; font-weight: 600; color: var(--text-muted); text-transform: uppercase; letter-spacing: 1px; }
.mh-bar a { color: var(--mint); transition: text-shadow 0.3s; }
.mh-bar a:hover { text-shadow: 0 0 10px var(--mint-glow); }
.logo-mark { font-family: var(--font-display); font-size: clamp(3.5rem, 8vw, 7rem); line-height: 0.9; letter-spacing: -0.04em; color: var(--text-pure); text-transform: uppercase; margin-bottom: 15px; animation: fadeUp 0.8s cubic-bezier(0.16, 1, 0.3, 1) both; }
.logo-mark .acc { color: var(--mint); display: block; }
@keyframes fadeUp { from { opacity: 0; transform: translateY(30px); } to { opacity: 1; transform: translateY(0); } }

.badge-row { display: flex; gap: 12px; justify-content: center; flex-wrap: wrap; margin-top: 20px; }
.badge { display: inline-flex; align-items: center; gap: 6px; background: rgba(0, 212, 224, 0.05); border: 1px solid var(--border-mint); color: var(--mint); font-size: 0.7rem; font-weight: 700; letter-spacing: 0.05em; text-transform: uppercase; padding: 6px 16px; border-radius: 100px; backdrop-filter: blur(4px); }
.badge .pulse { width: 6px; height: 6px; border-radius: 50%; background: var(--mint); box-shadow: 0 0 8px var(--mint); animation: blink 2s infinite; }
@keyframes blink { 0%, 100% { opacity: 1; } 50% { opacity: 0.3; } }

.nav-wrap { position: sticky; top: 0; z-index: 500; background: rgba(4, 9, 20, 0.75); backdrop-filter: blur(16px); border-bottom: 1px solid var(--border-subtle); }
.nav-inner { max-width: 1400px; margin: 0 auto; display: flex; align-items: center; justify-content: center; overflow-x: auto; scrollbar-width: none; padding: 0 20px; }
.nav-link { font-size: 0.8rem; font-weight: 600; letter-spacing: 0.05em; text-transform: uppercase; color: var(--text-muted); padding: 20px 18px; white-space: nowrap; position: relative; transition: 0.3s ease; }
.nav-link:hover { color: var(--text-pure); }
.nav-link.active { color: var(--mint); }
.nav-link::after { content: ''; position: absolute; bottom: -1px; left: 0; width: 100%; height: 2px; background: var(--mint); transform: scaleX(0); transition: transform 0.3s ease; }
.nav-link.active::after, .nav-link:hover::after { transform: scaleX(1); box-shadow: 0 -2px 10px var(--mint-glow); }

.page-wrap { max-width: 1400px; margin: 0 auto; padding: 40px 20px 80px; }
.sec-hd { display: flex; align-items: center; justify-content: space-between; margin-bottom: 30px; border-bottom: 1px solid var(--border-subtle); padding-bottom: 15px; }
.sec-hd-lbl { font-family: var(--font-display); font-size: 1.8rem; letter-spacing: -0.02em; text-transform: uppercase; }
.sec-hd-ct { font-size: 0.8rem; font-weight: 600; color: var(--mint); text-transform: uppercase; letter-spacing: 1px; }

.hero-grid { display: grid; grid-template-columns: 2fr 1fr; gap: 20px; margin-bottom: 50px; }
@media (max-width: 960px) { .hero-grid { grid-template-columns: 1fr; } }
.hero-main, .hero-side { position: relative; overflow: hidden; border-radius: 16px; border: 1px solid var(--border-subtle); background: var(--bg-surface); transition: transform 0.3s, border-color 0.3s; }
.hero-main { aspect-ratio: 16/9; } .hero-side { aspect-ratio: 4/3; }
.hero-main:hover, .hero-side:hover { transform: translateY(-4px); border-color: var(--mint); box-shadow: 0 10px 30px rgba(0, 0, 0, 0.5), 0 0 20px var(--mint-glow); }
.hero-main img, .hero-main video, .hero-side img, .hero-side video { width: 100%; height: 100%; object-fit: cover; border-radius: 0; }
.hero-ov { position: absolute; inset: 0; background: linear-gradient(to top, rgba(4, 9, 20, 0.95) 0%, rgba(4, 9, 20, 0.2) 60%, transparent 100%); }
.hero-ct { position: absolute; bottom: 0; left: 0; width: 100%; padding: 30px; }
.hero-tag { display: inline-block; background: var(--mint); color: var(--text-dark); font-size: 0.7rem; font-weight: 800; text-transform: uppercase; letter-spacing: 0.05em; padding: 6px 14px; border-radius: 100px; margin-bottom: 12px; }
.hero-ttl { font-family: var(--font-display); font-size: clamp(1.8rem, 4vw, 2.5rem); line-height: 1.1; margin-bottom: 10px; letter-spacing: -0.02em; }
.hero-sub { font-size: 1rem; color: var(--text-muted); display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden; }
.hero-meta { display: flex; align-items: center; gap: 10px; margin-top: 15px; font-size: 0.75rem; font-weight: 600; color: var(--text-muted); text-transform: uppercase; }
.hero-meta span:first-child { color: var(--text-pure); }
.side-cards-wrap { display: flex; flex-direction: column; gap: 20px; }

.g3 { display: grid; grid-template-columns: repeat(auto-fill, minmax(320px, 1fr)); gap: 24px; margin-bottom: 40px; }
.card { display: flex; flex-direction: column; background: var(--bg-surface); border-radius: 16px; border: 1px solid var(--border-subtle); overflow: hidden; transition: all 0.3s; position: relative; }
.card:hover { transform: translateY(-5px); border-color: var(--border-mint); background: var(--bg-surface-hover); box-shadow: 0 10px 25px rgba(0,0,0,0.4); }
.c-media { position: relative; aspect-ratio: 16/9; overflow: hidden; }
.c-media img, .c-media video { width: 100%; height: 100%; object-fit: cover; transition: transform 0.5s; border-radius: 0; }
.card:hover .c-media img, .card:hover .c-media video { transform: scale(1.05); }
.vid-pill { position: absolute; top: 12px; left: 12px; background: rgba(0,0,0,0.75); backdrop-filter: blur(4px); color: var(--text-pure); font-size: 0.65rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.05em; padding: 6px 12px; border-radius: 100px; display: flex; align-items: center; gap: 6px; border: 1px solid rgba(255,255,255,0.1); z-index: 2; }
.vid-pill::before { content: ''; width: 8px; height: 8px; background: #FF0000; border-radius: 50%; }
.feat-rib { position: absolute; top: 12px; right: 12px; background: var(--mint); color: var(--text-dark); font-size: 0.65rem; font-weight: 800; text-transform: uppercase; padding: 6px 12px; border-radius: 100px; z-index: 2; }
.c-body { padding: 20px; display: flex; flex-direction: column; flex: 1; }
.c-tag { color: var(--mint); font-size: 0.7rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.05em; margin-bottom: 8px; }
.c-ttl { font-family: var(--font-display); font-size: 1.25rem; line-height: 1.2; margin-bottom: 10px; letter-spacing: -0.01em; }
.c-sub { font-size: 0.85rem; color: var(--text-muted); line-height: 1.5; margin-bottom: 15px; display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden; }
.c-meta { margin-top: auto; padding-top: 15px; border-top: 1px solid var(--border-subtle); display: flex; justify-content: space-between; font-size: 0.75rem; font-weight: 500; color: var(--text-muted); }

.single-wrap { display: grid; grid-template-columns: 1fr 340px; gap: 50px; align-items: start; }
@media (max-width: 1024px) { .single-wrap { grid-template-columns: 1fr; } }
.breadcrumb { font-size: 0.8rem; font-weight: 600; color: var(--text-muted); text-transform: uppercase; margin-bottom: 20px; }
.breadcrumb a { color: var(--mint); margin-right: 5px; }
.art-hdr { margin-bottom: 30px; }
.art-pill { display: inline-block; background: rgba(0, 212, 224, 0.1); color: var(--mint); padding: 8px 16px; border-radius: 100px; font-size: 0.75rem; font-weight: 800; text-transform: uppercase; margin-bottom: 20px; border: 1px solid var(--border-mint); }
.art-ttl { font-family: var(--font-display); font-size: clamp(2.5rem, 5vw, 4rem); line-height: 1; margin-bottom: 20px; letter-spacing: -0.03em; }
.art-sub { font-size: 1.25rem; color: var(--text-muted); line-height: 1.5; font-weight: 300; margin-bottom: 25px; }
.art-byline { display: flex; align-items: center; gap: 15px; padding: 15px 0; border-top: 1px solid var(--border-subtle); border-bottom: 1px solid var(--border-subtle); font-size: 0.85rem; color: var(--text-muted); text-transform: uppercase; font-weight: 600; margin-bottom: 30px; }
.by-author { color: var(--text-pure); font-weight: 800; }

.yt-container { position: relative; padding-bottom: 56.25%; height: 0; overflow: hidden; border-radius: 16px; margin-bottom: 40px; box-shadow: 0 20px 40px rgba(0,0,0,0.5); border: 1px solid var(--border-subtle); background: #000; }
.yt-container iframe { position: absolute; top: 0; left: 0; width: 100%; height: 100%; border: 0; }
.art-media { width: 100%; max-height: 600px; object-fit: cover; border-radius: 16px; margin-bottom: 40px; box-shadow: 0 20px 40px rgba(0,0,0,0.5); border: 1px solid var(--border-subtle); }

.art-body { font-size: 1.1rem; line-height: 1.8; color: #D1D5DB; }
.art-body p { margin-bottom: 1.5em; }
.art-body h2 { font-family: var(--font-display); font-size: 1.8rem; color: var(--text-pure); margin: 2em 0 1em; letter-spacing: -0.02em; }
.art-body blockquote { border-left: 4px solid var(--mint); background: var(--bg-surface); padding: 20px 30px; border-radius: 0 12px 12px 0; font-size: 1.25rem; font-style: italic; color: var(--text-pure); margin: 2em 0; }

.s-aside { position: sticky; top: 100px; background: var(--bg-surface); border-radius: 16px; border: 1px solid var(--border-subtle); padding: 25px; }
.s-aside-hd { font-family: var(--font-display); font-size: 1.2rem; text-transform: uppercase; margin-bottom: 20px; color: var(--text-pure); }
.s-art { display: flex; gap: 15px; margin-bottom: 20px; padding-bottom: 20px; border-bottom: 1px solid var(--border-subtle); transition: 0.2s; }
.s-art:last-child { margin-bottom: 0; padding-bottom: 0; border-bottom: none; }
.s-art:hover .s-art-ttl { color: var(--mint); }
.s-thumb { width: 80px; height: 80px; border-radius: 8px; object-fit: cover; flex-shrink: 0; }
.s-art-tag { font-size: 0.65rem; font-weight: 800; text-transform: uppercase; color: var(--text-muted); margin-bottom: 5px; }
.s-art-ttl { font-weight: 600; font-size: 0.95rem; line-height: 1.3; color: var(--text-pure); }

.cd-hero { background: var(--bg-surface); border: 1px solid var(--border-mint); border-radius: 16px; padding: 40px; margin-bottom: 50px; position: relative; overflow: hidden; display: flex; align-items: center; justify-content: space-between; }
.cd-hero::before { content: ''; position: absolute; top: 0; right: 0; bottom: 0; width: 50%; background: radial-gradient(circle at right, var(--mint-glow) 0%, transparent 70%); pointer-events: none; }
.cd-label { font-size: 0.8rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.2em; color: var(--mint); margin-bottom: 10px; }
.cd-big { font-family: var(--font-display); font-size: 3rem; line-height: 1; text-transform: uppercase; letter-spacing: -0.02em; }
.cd-boxes { display: flex; gap: 15px; }
.cd-box { background: rgba(0, 212, 224, 0.05); border: 1px solid rgba(0, 212, 224, 0.2); border-radius: 12px; padding: 15px 20px; text-align: center; min-width: 90px; backdrop-filter: blur(4px); }
.cd-n { font-family: var(--font-display); font-size: 2.5rem; color: var(--text-pure); display: block; line-height: 1; }
.cd-l { font-size: 0.7rem; font-weight: 600; text-transform: uppercase; color: var(--text-muted); letter-spacing: 0.1em; margin-top: 5px; display: block; }
@media (max-width: 768px) { .cd-hero { flex-direction: column; text-align: center; gap: 30px; } }

.btn { display: inline-flex; align-items: center; justify-content: center; gap: 8px; font-family: var(--font-body); font-size: 0.8rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.05em; padding: 12px 24px; border-radius: 100px; cursor: pointer; transition: all 0.3s; border: none; outline: none; }
.btn-gd { background: var(--mint); color: var(--text-dark); }
.btn-gd:hover { background: #00E6F2; box-shadow: 0 0 20px var(--mint-glow); transform: translateY(-2px); }
.btn-nv { background: rgba(255,255,255,0.1); color: var(--text-pure); border: 1px solid var(--border-subtle); }
.btn-nv:hover { background: rgba(255,255,255,0.15); border-color: var(--mint); color: var(--mint); }
.btn-rd { background: rgba(239, 68, 68, 0.1); color: #EF4444; border: 1px solid rgba(239, 68, 68, 0.3); }
.btn-rd:hover { background: #EF4444; color: white; }
.btn-sm { padding: 8px 16px; font-size: 0.7rem; }

/* ─── ADMIN PANEL ─────────────────────────────────── */
.adm { position: fixed; inset: 0; background: var(--bg-dark); z-index: 10000; overflow-y: auto; color: var(--text-pure); }
.adm-top { background: var(--bg-surface); border-bottom: 1px solid var(--border-subtle); padding: 15px 30px; display: flex; align-items: center; justify-content: space-between; position: sticky; top: 0; z-index: 100; backdrop-filter: blur(10px); }
.adm-logo { font-family: var(--font-display); font-size: 1.5rem; color: var(--text-pure); }
.adm-logo span { color: var(--mint); }
.adm-body { max-width: 1000px; margin: 40px auto; padding: 0 20px; }
.adm-card { background: var(--bg-surface); border: 1px solid var(--border-subtle); border-radius: 16px; padding: 40px; margin-bottom: 40px; }
.adm-card-ttl { font-family: var(--font-display); font-size: 1.5rem; margin-bottom: 25px; color: var(--mint); }

.f2 { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; }
.fg { display: flex; flex-direction: column; gap: 8px; margin-bottom: 20px; }
.fl { font-size: 0.75rem; font-weight: 700; text-transform: uppercase; color: var(--text-muted); letter-spacing: 0.05em; }
.fi { background: rgba(0,0,0,0.2); border: 1px solid var(--border-subtle); color: var(--text-pure); padding: 14px 16px; font-family: var(--font-body); font-size: 0.9rem; border-radius: 8px; transition: 0.3s; }
.fi:focus { outline: none; border-color: var(--mint); box-shadow: 0 0 0 3px rgba(0, 212, 224, 0.1); background: rgba(0,0,0,0.4); }
textarea.fi { resize: vertical; min-height: 200px; }

.fchk { display: flex; align-items: center; gap: 10px; cursor: pointer; background: rgba(0,0,0,0.2); padding: 15px; border-radius: 8px; border: 1px solid var(--border-subtle); }
.fchk input { accent-color: var(--mint); width: 18px; height: 18px; }
.fchk span { font-size: 0.9rem; font-weight: 600; }

.adm-sec-ttl { font-family: var(--font-display); font-size: 1.5rem; margin-bottom: 20px; }
.adm-lst { display: flex; flex-direction: column; gap: 10px; }
.adm-row { display: grid; grid-template-columns: 60px 1fr auto auto auto auto; align-items: center; gap: 15px; background: var(--bg-surface); border: 1px solid var(--border-subtle); padding: 12px; border-radius: 12px; }
.adm-row:hover { border-color: var(--border-mint); }
.adm-thumb { width: 60px; height: 45px; object-fit: cover; border-radius: 6px; }
.adm-ttl { font-weight: 600; font-size: 0.95rem; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.adm-sec { font-size: 0.7rem; font-weight: 700; text-transform: uppercase; color: var(--mint); background: rgba(0, 212, 224, 0.1); padding: 4px 10px; border-radius: 100px; }

/* Login */
.lg-wrap { display: flex; align-items: center; justify-content: center; min-height: 100vh; }
.lg-card { background: var(--bg-surface); border: 1px solid var(--border-mint); padding: 50px 40px; border-radius: 20px; width: 100%; max-width: 420px; text-align: center; }
.lg-logo { font-family: var(--font-display); font-size: 3rem; margin-bottom: 10px; }
.lg-logo span { color: var(--mint); }
.lg-err { background: rgba(239, 68, 68, 0.1); color: #FCA5A5; padding: 12px; border-radius: 8px; margin-bottom: 20px; font-weight: 600; font-size: 0.85rem; }

/* Setup Seguro */
.lg-setup { background: rgba(28, 78, 216, 0.1); border: 1px solid var(--accent-blue); padding: 30px; border-radius: 16px; text-align: left; }
.lg-setup h3 { font-family: var(--font-display); color: var(--mint); margin-bottom: 15px; }
.lg-setup p { font-size: 0.9rem; color: #D1D5DB; line-height: 1.5; margin-bottom: 15px; }
.hash-box { background: #000; color: var(--mint); font-family: monospace; font-size: 0.9rem; padding: 15px; border-radius: 8px; word-break: break-all; margin-bottom: 15px; border: 1px solid var(--border-mint); }
</style>
</head>
<body>

<?php if ($adminArea): ?>
<!-- ════ ADMIN PANEL ════ -->
<div class="adm">
  <?php if (!$isAdmin): ?>
  <div class="lg-wrap">
    <div class="lg-card" style="<?= $setupMode ? 'max-width: 550px;' : '' ?>">
      <div class="lg-logo">CUENTO<br><span>MUNDIAL</span></div>
      <div style="font-weight:600; color:var(--text-muted); margin-bottom:30px; letter-spacing:0.1em;">SYSTEM | REDACCIÓN</div>
      
      <?php if($setupMode): ?>
        <div class="lg-setup">
          <h3>🔐 MODO SETUP DE SEGURIDAD</h3>
          <p>Por políticas de nivel agencia, las contraseñas no se almacenan en texto plano en el código. El sistema ha generado un Hash Bcrypt seguro para la contraseña que acabas de escribir.</p>
          <p><b>Instrucciones:</b><br>1. Copia este bloque de código exacto.<br>2. Pégalo en la línea 10 de tu archivo <code>cuento_mundial.php</code>, entre las comillas simples de <i>ADMIN_PASS_HASH</i>.</p>
          <div class="hash-box"><?=htmlspecialchars($newHash)?></div>
          <p style="margin-bottom:0; font-size:0.8rem; font-weight:600;">Una vez guardado tu archivo, recarga esta página y vuelve a intentar.</p>
        </div>
      <?php else: ?>
        <?php if(isset($loginError)):?><div class="lg-err">⚠ <?=htmlspecialchars($loginError)?></div><?php endif?>
        <form method="POST">
          <input type="password" name="login_pass" class="fi" placeholder="Contraseña de acceso" style="width:100%; text-align:center; font-size:1.1rem; margin-bottom:20px;" autofocus required>
          <button type="submit" class="btn btn-gd" style="width:100%;">Acceder al Sistema</button>
        </form>
      <?php endif; ?>
    </div>
  </div>

  <?php else: ?>
  <div class="adm-top">
    <div class="adm-logo">CUENTO <span>MUNDIAL</span> / SYSTEM</div>
    <div style="display:flex; gap:10px;">
      <a href="?" class="btn btn-nv btn-sm">Ver Sitio</a>
      <a href="?admin&logout" class="btn btn-rd btn-sm">Salir</a>
    </div>
  </div>
  <div class="adm-body">
    <div class="adm-card">
      <div class="adm-card-ttl"><?=$editArticle?'Editar Contenido':'Nuevo Contenido'?></div>
      <form method="POST" enctype="multipart/form-data">
        <?php if($editArticle):?><input type="hidden" name="edit_id" value="<?=$editArticle['id']?>"><?php endif?>
        
        <div class="f2">
          <div class="fg">
            <label class="fl">Titular Principal</label>
            <input type="text" name="title" class="fi" required placeholder="Ej. El Fútbol se vive aquí" value="<?=$editArticle?htmlspecialchars($editArticle['title']):''?>">
          </div>
          <div class="fg">
            <label class="fl">Subtítulo / Bajada</label>
            <input type="text" name="subtitle" class="fi" placeholder="Texto descriptivo breve" value="<?=$editArticle?htmlspecialchars($editArticle['subtitle']):''?>">
          </div>
        </div>
        
        <div class="f2">
          <div class="fg">
            <label class="fl">Categoría</label>
            <select name="section" class="fi" required>
              <?php foreach($SECTIONS as $k=>$s):if($k==='portada')continue?>
                <option value="<?=$k?>" <?=($editArticle&&$editArticle['section']===$k)?'selected':''?>><?=$s['label']?></option>
              <?php endforeach?>
            </select>
          </div>
          <div class="fg">
            <label class="fl">Autor</label>
            <input type="text" name="author" class="fi" placeholder="Nombre del redactor" value="<?=$editArticle?htmlspecialchars($editArticle['author']):''?>">
          </div>
        </div>
        
        <div class="fg">
          <label class="fl">Cuerpo del Artículo</label>
          <textarea name="body" class="fi" required placeholder="HTML Básico permitido: <p>, <h2>, <blockquote>, <b>..."><?=$editArticle?htmlspecialchars($editArticle['body']):''?></textarea>
        </div>

        <!-- SECCIÓN MEDIA YOUTUBE / ARCHIVO -->
        <div style="border-top: 1px solid var(--border-subtle); padding-top: 25px; margin-top: 15px;">
          <div class="f2">
            <div class="fg">
              <label class="fl">Enlace de YouTube (Recomendado)</label>
              <input type="text" name="youtube" class="fi" placeholder="Ej: https://www.youtube.com/watch?v=..." value="<?=$editArticle&&!empty($editArticle['youtube'])?'https://youtube.com/watch?v='.htmlspecialchars($editArticle['youtube']):''?>">
              <div style="font-size:0.7rem; color:var(--mint); margin-top:4px;">Extraerá la miniatura en HD automáticamente.</div>
            </div>
            <div class="fg">
              <label class="fl">O Sube un Archivo (Imágenes/MP4)</label>
              <input type="file" name="media" class="fi" accept="image/*,video/*">
              <?php if($editArticle&&!empty($editArticle['media'])):?>
                <div style="font-size:0.75rem; color:var(--text-muted); margin-top:8px;">Actual: <span style="color:var(--text-pure)"><?=htmlspecialchars($editArticle['media'])?></span></div>
              <?php endif?>
            </div>
          </div>
        </div>

        <label class="fchk" style="margin-top: 10px;">
          <input type="checkbox" name="featured" value="1" <?=($editArticle&&$editArticle['featured'])?'checked':''?>>
          <span>Destacar en Portada (Hero Section Principal)</span>
        </label>
        
        <div style="display:flex; gap:15px; margin-top:30px;">
          <button type="submit" name="save_article" class="btn btn-gd"><?=$editArticle?'Guardar Cambios':'Publicar Ahora'?></button>
          <?php if($editArticle):?><a href="?admin" class="btn btn-nv">Cancelar</a><?php endif?>
        </div>
      </form>
    </div>

    <div class="adm-sec-ttl">Contenido Publicado</div>
    <?php if(empty($articles)):?>
      <div style="text-align:center;padding:40px;color:var(--text-muted);border:1px dashed var(--border-subtle);border-radius:12px;">Aún no hay artículos.</div>
    <?php else:?>
    <div class="adm-lst">
      <?php foreach($articles as $a):?>
      <div class="adm-row">
        <?php if(!empty($a['youtube'])):?>
          <img src="https://img.youtube.com/vi/<?=$a['youtube']?>/default.jpg" class="adm-thumb" alt="YT">
        <?php elseif(!empty($a['media'])):?>
          <?php if(isVideo($a['media'])):?><div class="adm-thumb" style="display:flex;align-items:center;justify-content:center;font-size:1.2rem;background:#1a2a45">🎬</div>
          <?php else:?><img src="uploads/<?=htmlspecialchars($a['media'])?>" class="adm-thumb" alt="">
          <?php endif?>
        <?php else:?><div class="adm-thumb" style="background:#0a1326"></div>
        <?php endif?>
        
        <div class="adm-ttl"><?=htmlspecialchars($a['title'])?></div>
        <div class="adm-sec"><?=$SECTIONS[$a['section']]['label']??$a['section']?></div>
        <div><?=$a['featured']?'<span style="color:var(--mint)">★ Destacado</span>':''?></div>
        <div class="adm-dt"><?=date('d/m H:i',$a['created'])?></div>
        <div style="display:flex;gap:8px;">
          <a href="?admin&edit=<?=$a['id']?>" class="btn btn-nv btn-sm">Editar</a>
          <a href="?admin&delete=<?=$a['id']?>" class="btn btn-rd btn-sm" onclick="return confirm('¿Eliminar definitivamente?')">Borrar</a>
        </div>
      </div>
      <?php endforeach?>
    </div>
    <?php endif?>
  </div>
  <?php endif?>
</div>

<?php else: ?>
<!-- ════ APP PÚBLICA ════ -->

<div class="alert-strip">
  <div class="alert-ticker">
    <span>Streaming Premium de la Copa del Mundo 2026</span>
    <span>Sigue toda la información en vivo</span>
    <span>48 Selecciones buscando la gloria en USA, Canadá y México</span>
    <span>Entrevistas, previas, análisis tácticos y resúmenes</span>
  </div>
</div>

<header class="masthead">
  <div class="mh-bar"><span><?=$hoy?></span><a href="?admin">System Access</a></div>
  <div class="logo-mark">CUENTO<span class="acc">MUNDIAL</span></div>
  <div style="font-size: 0.9rem; font-weight: 600; color: var(--text-muted); text-transform: uppercase; letter-spacing: 0.2em;">El Fútbol se vive en Otro Nivel</div>
  <div class="badge-row">
    <div class="badge"><span class="pulse"></span>En Vivo</div>
    <div class="badge">Usa / Can / Mex</div>
  </div>
</header>

<nav class="nav-wrap">
  <div class="nav-inner">
    <?php foreach($SECTIONS as $k=>$s):?>
    <a href="?s=<?=$k?>" class="nav-link <?=$activeSection===$k?'active':''?>"><?=$s['label']?></a>
    <?php endforeach?>
  </div>
</nav>

<main class="page-wrap">

<?php if($singleArticle): ?>
<!-- LECTURA DE ARTÍCULO -->
<div class="single-wrap reveal">
  <article>
    <div class="breadcrumb">
      <a href="?">Home</a> / <a href="?s=<?=$singleArticle['section']?>"><?=$SECTIONS[$singleArticle['section']]['label']??''?></a> / Artículo
    </div>
    
    <div class="art-hdr">
      <div class="art-pill"><?=$SECTIONS[$singleArticle['section']]['label']??''?></div>
      <h1 class="art-ttl"><?=htmlspecialchars($singleArticle['title'])?></h1>
      <?php if(!empty($singleArticle['subtitle'])):?><p class="art-sub"><?=htmlspecialchars($singleArticle['subtitle'])?></p><?php endif?>
      
      <div class="art-byline">
        <span class="by-author"><?=!empty($singleArticle['author'])?htmlspecialchars($singleArticle['author']):'Desk'?></span>
        <span>—</span>
        <span><?=date('d/m/Y H:i',$singleArticle['created'])?></span>
      </div>
    </div>

    <!-- REPRODUCTOR YOUTUBE INCORPORADO (O MEDIA LOCAL) -->
    <?php if(!empty($singleArticle['youtube'])): ?>
      <div class="yt-container">
        <iframe src="https://www.youtube.com/embed/<?=htmlspecialchars($singleArticle['youtube'])?>?autoplay=1&mute=1" allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture" allowfullscreen></iframe>
      </div>
    <?php elseif(!empty($singleArticle['media'])):?>
      <?php if(isVideo($singleArticle['media'])):?>
        <video class="art-media" controls autoplay muted><source src="uploads/<?=htmlspecialchars($singleArticle['media'])?>"></video>
      <?php else:?>
        <img src="uploads/<?=htmlspecialchars($singleArticle['media'])?>" class="art-media" alt="<?=htmlspecialchars($singleArticle['title'])?>">
      <?php endif?>
    <?php endif?>

    <div class="art-body">
      <?php $b=$singleArticle['body'];
        if(strpos($b,'<p>')===false && strpos($b,'<h2>')===false)
          $b='<p>'.implode('</p><p>',array_filter(explode("\n",$b))).'</p>';
        echo $b;?>
    </div>
  </article>

  <aside>
    <div class="s-aside">
      <div class="s-aside-hd">Relacionados</div>
      <?php foreach(array_slice(array_values(array_filter($articles,fn($a)=>$a['id']!==$singleArticle['id'])),0,5) as $r):?>
      <a href="?art=<?=$r['id']?>" class="s-art">
        <?php if(!empty($r['youtube'])):?>
          <img src="https://img.youtube.com/vi/<?=$r['youtube']?>/default.jpg" class="s-thumb" alt="">
        <?php elseif(!empty($r['media'])&&!isVideo($r['media'])):?>
          <img src="uploads/<?=htmlspecialchars($r['media'])?>" class="s-thumb" alt="">
        <?php else:?>
          <div class="s-thumb" style="background:#1a2a45"></div>
        <?php endif?>
        <div>
          <div class="s-art-tag"><?=$SECTIONS[$r['section']]['label']??''?></div>
          <div class="s-art-ttl"><?=htmlspecialchars($r['title'])?></div>
        </div>
      </a>
      <?php endforeach?>
    </div>
  </aside>
</div>

<?php elseif($activeSection==='portada'): ?>
<!-- PORTADA PRINCIPAL -->

<div class="cd-hero reveal">
  <div><div class="cd-label">Kick-Off 2026</div><div class="cd-big">Cuenta<br>Regresiva</div></div>
  <div class="cd-boxes" id="countdown">
    <div class="cd-box"><span class="cd-n" id="cd-d">--</span><span class="cd-l">Días</span></div>
    <div class="cd-box"><span class="cd-n" id="cd-h">--</span><span class="cd-l">Horas</span></div>
    <div class="cd-box"><span class="cd-n" id="cd-m">--</span><span class="cd-l">Min</span></div>
    <div class="cd-box"><span class="cd-n" id="cd-s">--</span><span class="cd-l">Seg</span></div>
  </div>
</div>

<?php
$featuredArts = array_values(array_filter($articles,fn($a)=>$a['featured']));
$hMain  = $featuredArts[0] ?? $articles[0] ?? null;
$hSide1 = $featuredArts[1] ?? $articles[1] ?? null;
$hSide2 = $featuredArts[2] ?? $articles[2] ?? null;
$skip   = ($hMain?1:0)+($hSide1?1:0)+($hSide2?1:0);
$gArts  = array_slice($articles,$skip,12);
?>

<?php if($hMain||!empty($articles)):?>

<!-- HERO EDITORIAL -->
<?php if($hMain):?>
<div class="hero-grid reveal">
  <a href="?art=<?=$hMain['id']?>" class="hero-main">
    <?=renderCardMedia($hMain, true)?>
    <div class="hero-ov"></div>
    <div class="hero-ct">
      <div class="hero-tag"><?=$SECTIONS[$hMain['section']]['label']??''?></div>
      <h2 class="hero-ttl"><?=htmlspecialchars($hMain['title'])?></h2>
      <?php if(!empty($hMain['subtitle'])):?><p class="hero-sub"><?=htmlspecialchars($hMain['subtitle'])?></p><?php endif?>
      <div class="hero-meta"><span><?=!empty($hMain['author'])?htmlspecialchars($hMain['author']):'Desk'?></span><span>•</span><span><?=timeAgo($hMain['created'])?></span></div>
    </div>
  </a>

  <div class="side-cards-wrap">
    <?php if($hSide1):?>
    <a href="?art=<?=$hSide1['id']?>" class="hero-side">
      <?=renderCardMedia($hSide1, true)?>
      <div class="hero-ov"></div>
      <div class="hero-ct" style="padding: 20px;">
        <div class="hero-tag" style="font-size: 0.6rem; padding: 4px 10px;"><?=$SECTIONS[$hSide1['section']]['label']??''?></div>
        <h2 class="hero-ttl" style="font-size: 1.2rem; margin-bottom:0;"><?=htmlspecialchars($hSide1['title'])?></h2>
      </div>
    </a>
    <?php endif?>
    
    <?php if($hSide2):?>
    <a href="?art=<?=$hSide2['id']?>" class="hero-side">
      <?=renderCardMedia($hSide2, true)?>
      <div class="hero-ov"></div>
      <div class="hero-ct" style="padding: 20px;">
        <div class="hero-tag" style="font-size: 0.6rem; padding: 4px 10px;"><?=$SECTIONS[$hSide2['section']]['label']??''?></div>
        <h2 class="hero-ttl" style="font-size: 1.2rem; margin-bottom:0;"><?=htmlspecialchars($hSide2['title'])?></h2>
      </div>
    </a>
    <?php endif?>
  </div>
</div>
<?php endif?>

<?php if(!empty($gArts)):?>
<div class="sec-hd reveal"><div class="sec-hd-lbl">Feed Principal</div></div>
<div class="g3">
  <?php foreach($gArts as $i=>$a):?>
  <a href="?art=<?=$a['id']?>" class="card reveal" style="transition-delay:<?=($i%3)*0.1?>s">
    <div class="c-media">
      <?=renderCardMedia($a, false)?>
      <?php if($a['featured']):?><div class="feat-rib">Top</div><?php endif?>
    </div>
    <div class="c-body">
      <div class="c-tag"><?=$SECTIONS[$a['section']]['label']??''?></div>
      <h3 class="c-ttl"><?=htmlspecialchars($a['title'])?></h3>
      <div class="c-meta"><span><?=!empty($a['author'])?htmlspecialchars($a['author']):'Desk'?></span><span><?=timeAgo($a['created'])?></span></div>
    </div>
  </a>
  <?php endforeach?>
</div>
<?php endif?>

<?php else:?>
<div class="empty reveal"><div class="empty-ico">🚫</div><h3>No Content</h3><p>Accede al panel para publicar contenido.</p></div>
<?php endif?>

<?php else: ?>
<!-- VISTAS POR CATEGORÍA -->
<div class="sec-hd reveal"><div class="sec-hd-lbl"><?=$SECTIONS[$activeSection]['label']?></div><div class="sec-hd-ct"><?=count($sectionArt)?> Archivos</div></div>
<?php if(empty($sectionArt)):?>
<div class="empty reveal"><div class="empty-ico">📡</div><h3>No Signal</h3><p>Aún no hay contenido aquí.</p></div>
<?php else:?>
<div class="g3">
  <?php foreach($sectionArt as $i=>$a):?>
  <a href="?art=<?=$a['id']?>" class="card reveal" style="transition-delay:<?=($i%3)*0.1?>s">
    <div class="c-media"><?=renderCardMedia($a, false)?></div>
    <div class="c-body">
      <div class="c-tag"><?=$SECTIONS[$a['section']]['label']??''?></div>
      <h3 class="c-ttl"><?=htmlspecialchars($a['title'])?></h3>
      <div class="c-meta"><span><?=!empty($a['author'])?htmlspecialchars($a['author']):'Desk'?></span><span><?=timeAgo($a['created'])?></span></div>
    </div>
  </a>
  <?php endforeach?>
</div>
<?php endif?>
<?php endif?>

</main>

<footer>
  <div class="ft-inner" style="max-width:1400px; margin:0 auto; display:grid; grid-template-columns:repeat(auto-fit, minmax(200px, 1fr)); gap:50px; padding:40px 20px;">
    <div style="grid-column: span 2;">
      <div class="logo-mark" style="font-size:2rem; animation:none; margin-bottom:10px;">CUENTO<br><span style="color:var(--mint)">MUNDIAL</span></div>
      <p style="color:var(--text-muted); font-size:0.9rem; line-height:1.6;">El sistema digital de información premium dedicado a la Copa del Mundo 2026. Data en vivo y performance de élite.</p>
    </div>
  </div>
  <div style="max-width:1400px; margin:0 auto; padding:30px 20px; border-top:1px solid var(--border-subtle); display:flex; justify-content:space-between; font-size:0.8rem; color:var(--text-muted); font-weight:500;">
    <span>© <?=date('Y')?> CUENTO MUNDIAL.</span>
    <a href="?admin" style="color:var(--mint); font-weight:700; text-transform:uppercase;">Internal Dashboard</a>
  </div>
</footer>

<?php endif ?>

<script>
(function(){
  const T = new Date('2026-06-11T11:00:00-06:00').getTime();
  const fmt = n => String(n).padStart(2,'0');
  const $ = id => document.getElementById(id);
  function tick(){
    const diff = T - Date.now();
    if(diff <= 0){ const el=$('countdown'); if(el) el.innerHTML='<div style="font-size:2rem;color:var(--mint);">LIVE NOW</div>'; return; }
    const d=Math.floor(diff/86400000), h=Math.floor((diff%86400000)/3600000), m=Math.floor((diff%3600000)/60000), s=Math.floor((diff%60000)/1000);
    if($('cd-d')) $('cd-d').textContent=d; if($('cd-h')) $('cd-h').textContent=fmt(h); if($('cd-m')) $('cd-m').textContent=fmt(m); if($('cd-s')) $('cd-s').textContent=fmt(s);
  }
  tick(); setInterval(tick, 1000);
})();

(function(){
  const obs = new IntersectionObserver(entries => {
    entries.forEach(e => { if(e.isIntersecting){ e.target.classList.add('in'); obs.unobserve(e.target); } });
  }, { threshold: 0.1 });
  document.querySelectorAll('.reveal').forEach(el => obs.observe(el));
})();
</script>
</body>
</html>