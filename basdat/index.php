<?php
/** Dashboard Kelas PTI A–E · UNESA — 1 file PHP (+HTML), Login Multi-Role (Admin, Dosen, Mahasiswa), 5 tabel relasional + query JOIN.
 *  Jalankan: import db_kelas_mahasiswa.sql -> htdocs (XAMPP) -> http://localhost/index.php · Gambar opsional: assets/hero.jpg, assets/foto/{NIM}.jpg, assets/mk/{KODE}.jpg */
session_start();
$DB = ['host' => 'localhost', 'name' => 'db_kelas_mahasiswa', 'user' => 'root', 'pass' => ''];
$KELAS = ['nama' => 'PTI A – E', 'prodi' => 'Pendidikan Teknologi Informasi', 'fakultas' => 'Fakultas Teknik',
          'kampus' => 'Universitas Negeri Surabaya', 'gedung' => 'Gedung A10 Teknik Informatika', 'tahun' => '2026/2027 Ganjil'];
$MAX_SKS = 24; $NILAI = ['A', 'AB', 'B', 'BC', 'C', 'D', 'E'];   // batas SKS semester berjalan & daftar nilai
try {
    $pdo = new PDO("mysql:host={$DB['host']};dbname={$DB['name']};charset=utf8mb4", $DB['user'], $DB['pass'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
} catch (PDOException $e) {
    die('<p style="font-family:sans-serif;padding:20px">Koneksi database gagal. Pastikan file SQL sudah di-import dan setting di atas benar.<br><small>' . htmlspecialchars($e->getMessage()) . '</small></p>');
}
class FieldError extends Exception {   // galat isian form: membawa nama kolom untuk ditandai merah
    public $field;
    function __construct($msg, $field = '') { parent::__construct($msg); $this->field = $field; }
}
if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(16));

// ====== UTIL ======
function h($s) { return htmlspecialchars((string)$s); }
function q($pdo, $sql, $p = []) { $s = $pdo->prepare($sql); $s->execute($p); return $s; }
function asset_url($path) { foreach (['jpg', 'jpeg', 'png', 'webp'] as $e) if (is_file(__DIR__ . "/assets/$path.$e")) return "assets/$path.$e"; return null; }
function ints($r, $keys) { foreach ($keys as $c) if ($r[$c] !== null) $r[$c] = (int)$r[$c]; return $r; }
function overlap($a, $b) { return $a['hari'] && $a['hari'] === $b['hari'] && $a['jam_mulai'] < $b['jam_selesai'] && $b['jam_mulai'] < $a['jam_selesai']; }

// ====== METADATA KOLOM DOSEN ======
$DOSEN_LBL = ['nidn' => 'NIDN', 'nip' => 'NIP', 'nidk' => 'NIDK', 'nuptk' => 'NUPTK', 'jk' => 'Jenis kelamin',
              'jenis_kelamin' => 'Jenis kelamin', 'email' => 'Email', 'telepon' => 'Telepon', 'no_hp' => 'No. HP',
              'hp' => 'No. HP', 'bidang' => 'Bidang keahlian', 'keahlian' => 'Bidang keahlian', 'bidang_keahlian' => 'Bidang keahlian',
              'jabatan' => 'Jabatan', 'status' => 'Status', 'alamat' => 'Alamat'];
$dosenCols = [];
foreach ($pdo->query("SHOW COLUMNS FROM dosen") as $c) {
    if (!in_array($c['Field'], ['id', 'nama', 'password', 'created_at', 'updated_at'], true)) {
        $enum = [];
        if (preg_match("/^enum\((.*)\)$/i", $c['Type'], $m)) {
            $enum = array_map(fn($x) => trim($x, "'\""), str_getcsv($m[1], ',', "'", "\\"));
        }
        $max = preg_match('/\((\d+)\)/', $c['Type'], $m) ? (int)$m[1] : 150;
        $dosenCols[$c['Field']] = [
            'req'  => $c['Null'] === 'NO' && $c['Default'] === null,
            'null' => $c['Null'] === 'YES',
            'enum' => $enum,
            'max'  => $max,
        ];
    }
}

$USER = $_SESSION['user'] ?? null;

// ====== PROSES POST ======
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $flash = ['ok', 'Berhasil.']; $reopen = $reopenTab = $formErr = $oldPost = null; $isForm = false;
    $id = (int)($_POST['id'] ?? 0); $nim = trim($_POST['nim'] ?? '');
    try {
        if (!hash_equals($_SESSION['csrf'], $_POST['csrf'] ?? '')) throw new Exception('Token tidak valid, muat ulang halaman.');
        $act = $_POST['action'] ?? '';

        if ($act === 'login') {
            $uname = trim($_POST['username'] ?? '');
            $pass  = (string)($_POST['password'] ?? '');
            if ($uname === '' || $pass === '') throw new Exception('Username/NIM dan password wajib diisi.');
            $u = q($pdo, 'SELECT * FROM users WHERE username = ? LIMIT 1', [$uname])->fetch();
            if (!$u) {
                $u = q($pdo, 'SELECT u.* FROM users u JOIN mahasiswa m ON m.id = u.mahasiswa_id WHERE m.nim = ? LIMIT 1', [$uname])->fetch();
            }
            if (!$u || !password_verify($pass, $u['password'])) {
                $_SESSION['login_old'] = $uname;
                throw new Exception('Username/NIM atau password salah.');
            }
            session_regenerate_id(true);
            $_SESSION['user'] = [
                'id'           => (int)$u['id'],
                'username'     => $u['username'],
                'role'         => $u['role'],
                'nama'         => trim($u['nama']),
                'mahasiswa_id' => $u['mahasiswa_id'] !== null ? (int)$u['mahasiswa_id'] : null,
                'dosen_id'     => $u['dosen_id'] !== null ? (int)$u['dosen_id'] : null,
            ];
            unset($_SESSION['login_old']);
            $roleLabel = ['admin' => 'Admin', 'dosen' => 'Dosen', 'mahasiswa' => 'Mahasiswa'][$u['role']] ?? $u['role'];
            $flash = ['ok', 'Selamat datang, ' . trim($u['nama']) . " ($roleLabel)."];

        } elseif ($act === 'logout') {
            unset($_SESSION['user']);
            $flash = ['ok', 'Anda telah keluar dari sistem.'];

        } else {
            if (!$USER) throw new Exception('Sesi berakhir. Silakan login terlebih dahulu.');
            $role = $USER['role'];
            $myMhsId = (int)($USER['mahasiswa_id'] ?? 0);
            $myDsnId = (int)($USER['dosen_id'] ?? 0);

            if ($act === 'delete') {
                if ($role !== 'admin') throw new Exception('Hanya admin yang dapat menghapus data mahasiswa.');
                q($pdo, 'DELETE FROM mahasiswa WHERE id=?', [$id]);   // krs & users ikut terhapus (ON DELETE CASCADE)
                $flash = ['ok', 'Data mahasiswa dihapus.'];

            } elseif ($act === 'create' || $act === 'update') {
                if ($act === 'create' && $role !== 'admin') {
                    throw new Exception('Hanya admin yang dapat menambah data mahasiswa.');
                }
                if ($act === 'update' && $role !== 'admin' && !($role === 'mahasiswa' && $myMhsId === $id)) {
                    throw new Exception('Anda tidak memiliki izin untuk mengedit data mahasiswa ini.');
                }
                $isForm = 'mhs';
                $nama = trim($_POST['nama'] ?? '');
                $jk = $_POST['jk'] ?? '';
                $email = trim($_POST['email'] ?? '');

                if ($act === 'update' && $role === 'mahasiswa') {
                    $cur = q($pdo, 'SELECT * FROM mahasiswa WHERE id=?', [$id])->fetch();
                    if (!$cur) throw new Exception('Data mahasiswa tidak ditemukan.');
                    $nim = $cur['nim'];
                    $smt = (int)$cur['semester'];
                    $status = $cur['status'];
                    $kelasId = (int)$cur['kelas_id'];
                } else {
                    $smt = (int)($_POST['semester'] ?? 0);
                    $status = $_POST['status'] ?? '';
                    $kelasId = (int)($_POST['kelas_id'] ?? 0);
                }

                $v = [$nim, $nama, $jk, $email, $smt, $status, $kelasId];
                $kelasOk = q($pdo, 'SELECT COUNT(*) FROM kelas WHERE id=?', [$kelasId])->fetchColumn();
                foreach ([[$nim === '', 'NIM wajib diisi.', 'nim'], [$nama === '', 'Nama wajib diisi.', 'nama'], [!in_array($jk, ['L', 'P'], true), 'Jenis kelamin tidak valid.', 'jk'],
                          [$email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL), 'Format email tidak valid.', 'email'], [$smt < 1 || $smt > 14, 'Semester harus antara 1 dan 14.', 'semester'],
                          [!in_array($status, ['Aktif', 'Cuti', 'Nonaktif'], true), 'Status tidak valid.', 'status'], [!$kelasOk, 'Kelas tidak valid.', 'kelas_id']] as [$bad, $msg, $fld])
                    if ($bad) throw new FieldError($msg, $fld);

                if ($act === 'create') {
                    q($pdo, 'INSERT INTO mahasiswa (nim,nama,jk,email,semester,status,kelas_id) VALUES (?,?,?,?,?,?,?)', $v);
                    $flash = ['ok', 'Mahasiswa baru ditambahkan. Klik KRS untuk mengambilkan mata kuliah.'];
                } else {
                    q($pdo, 'UPDATE mahasiswa SET nim=?,nama=?,jk=?,email=?,semester=?,status=?,kelas_id=? WHERE id=?', array_merge($v, [$id]));
                    q($pdo, 'UPDATE users SET nama=? WHERE mahasiswa_id=?', [$nama, $id]);
                    if ($role === 'mahasiswa' && $myMhsId === $id) $_SESSION['user']['nama'] = $nama;
                    $flash = ['ok', 'Data mahasiswa diperbarui.'];
                }

            } elseif ($act === 'dosen_update') {
                if ($role !== 'admin' && !($role === 'dosen' && $myDsnId === $id)) {
                    throw new Exception('Anda tidak memiliki izin untuk mengedit data dosen ini.');
                }
                $isForm = 'dosen';
                $reopenTab = 'dosen';
                $oldRow = q($pdo, 'SELECT * FROM dosen WHERE id=?', [$id])->fetch();
                if (!$oldRow) throw new Exception('Data dosen tidak ditemukan.');
                $namaDosen = trim($_POST['nama'] ?? '');
                $mkIds = array_values(array_unique(array_filter(array_map('intval', (array)($_POST['mk'] ?? [])))));
                $oldPost = ['nama' => $namaDosen, 'mk' => $mkIds];
                if ($namaDosen === '') throw new FieldError('Nama wajib diisi.', 'nama');
                $sets = ['nama=?']; $vals = [$namaDosen];
                foreach ($dosenCols as $col => $meta) {
                    $val = trim($_POST[$col] ?? '');
                    $oldPost[$col] = $val;
                    $lbl = $DOSEN_LBL[$col] ?? ucfirst(str_replace('_', ' ', $col));
                    if ($meta['req'] && $val === '') throw new FieldError("$lbl wajib diisi.", $col);
                    if (strpos($col, 'email') !== false && $val !== '' && !filter_var($val, FILTER_VALIDATE_EMAIL))
                        throw new FieldError('Format email tidak valid.', $col);
                    if ($meta['enum'] && $val !== '' && !in_array($val, $meta['enum'], true))
                        throw new FieldError("$lbl tidak valid.", $col);
                    $sets[] = "$col=?";
                    $vals[] = ($val === '' && $meta['null']) ? null : $val;
                }
                if ($mkIds) {
                    $inCheck = implode(',', array_fill(0, count($mkIds), '?'));
                    $validMk = (int)q($pdo, "SELECT COUNT(*) FROM matakuliah WHERE id IN ($inCheck)", $mkIds)->fetchColumn();
                    if ($validMk !== count($mkIds)) throw new Exception('Salah satu mata kuliah yang dipilih tidak ditemukan.');
                }
                $pdo->beginTransaction();
                q($pdo, 'UPDATE dosen SET ' . implode(',', $sets) . ' WHERE id=?', array_merge($vals, [$id]));
                if (trim($oldRow['nama']) !== $namaDosen) {
                    q($pdo, 'UPDATE kelas SET wali=? WHERE wali=?', [$namaDosen, $oldRow['nama']]);
                }
                q($pdo, 'UPDATE users SET nama=? WHERE dosen_id=?', [$namaDosen, $id]);
                if ($role === 'dosen' && $myDsnId === $id) $_SESSION['user']['nama'] = $namaDosen;
                if ($mkIds) {
                    $in = implode(',', array_fill(0, count($mkIds), '?'));
                    q($pdo, "UPDATE matakuliah SET dosen_id = NULL WHERE dosen_id = ? AND id NOT IN ($in)", array_merge([$id], $mkIds));
                    q($pdo, "UPDATE matakuliah SET dosen_id = ? WHERE id IN ($in)", array_merge([$id], $mkIds));
                } else {
                    q($pdo, 'UPDATE matakuliah SET dosen_id = NULL WHERE dosen_id = ?', [$id]);
                }
                $pdo->commit();
                $flash = ['ok', 'Data dosen dan mata kuliah yang diampu diperbarui.'];

            } elseif ($act === 'krs_add') {
                $mid = (int)($_POST['mahasiswa_id'] ?? 0);
                if ($role !== 'admin' && !($role === 'mahasiswa' && $myMhsId === $mid)) {
                    throw new Exception('Anda tidak memiliki izin untuk mengubah KRS mahasiswa ini.');
                }
                $ids = array_values(array_unique(array_filter(array_map('intval', (array)($_POST['mk'] ?? [])))));
                if (!$ids) throw new Exception('Pilih minimal satu mata kuliah.');
                $reopen = [$mid, 'krs'];
                $pdo->beginTransaction();
                $in = implode(',', array_fill(0, count($ids), '?'));
                q($pdo, "SELECT id FROM matakuliah WHERE id IN ($in) FOR UPDATE", $ids);   // kunci baris supaya hitungan kuota akurat
                $m = q($pdo, 'SELECT * FROM mahasiswa WHERE id=?', [$mid])->fetch();
                if (!$m) throw new Exception('Mahasiswa tidak ditemukan.');
                if ($m['status'] !== 'Aktif') throw new Exception('Hanya mahasiswa berstatus Aktif yang dapat mengambil mata kuliah.');
                $pilih = q($pdo, "SELECT mk.*, (SELECT COUNT(*) FROM krs x WHERE x.matakuliah_id = mk.id) AS terisi FROM matakuliah mk WHERE mk.id IN ($in)", $ids)->fetchAll();
                if (count($pilih) !== count($ids)) throw new Exception('Mata kuliah tidak ditemukan.');
                $sudah = array_column(q($pdo, 'SELECT matakuliah_id FROM krs WHERE mahasiswa_id=?', [$mid])->fetchAll(), 'matakuliah_id');
                $aktif = q($pdo, 'SELECT mk.nama, mk.sks, mk.hari, mk.jam_mulai, mk.jam_selesai FROM krs k JOIN matakuliah mk ON mk.id = k.matakuliah_id
                                  WHERE k.mahasiswa_id=? AND k.nilai IS NULL', [$mid])->fetchAll();
                $beban = array_sum(array_column($aktif, 'sks')); $dipilih = [];
                foreach ($pilih as $mk) {
                    if (in_array($mk['id'], $sudah)) throw new Exception("{$mk['nama']} sudah pernah diambil.");
                    if ((int)$mk['terisi'] >= (int)$mk['kuota']) throw new Exception("Kuota {$mk['nama']} sudah penuh.");
                    foreach (array_merge($aktif, $dipilih) as $o)
                        if (overlap($mk, $o)) throw new Exception("Jadwal {$mk['nama']} bentrok dengan {$o['nama']} ({$mk['hari']} " . substr($mk['jam_mulai'], 0, 5) . '–' . substr($mk['jam_selesai'], 0, 5) . ').');
                    $beban += (int)$mk['sks'];
                    $dipilih[] = $mk;
                }
                if ($beban > $MAX_SKS) throw new Exception("Total SKS berjalan menjadi $beban, melebihi batas $MAX_SKS SKS.");
                foreach ($dipilih as $mk) q($pdo, 'INSERT INTO krs (mahasiswa_id, matakuliah_id) VALUES (?,?)', [$mid, $mk['id']]);
                $pdo->commit();
                $flash = ['ok', count($dipilih) . " mata kuliah ditambahkan ke KRS (total berjalan $beban SKS)."];

            } elseif ($act === 'krs_drop') {
                $r = q($pdo, 'SELECT k.mahasiswa_id, k.nilai, mk.nama FROM krs k JOIN matakuliah mk ON mk.id = k.matakuliah_id WHERE k.id=?', [(int)($_POST['krs_id'] ?? 0)])->fetch();
                if (!$r) throw new Exception('Data KRS tidak ditemukan.');
                if ($role !== 'admin' && !($role === 'mahasiswa' && $myMhsId === (int)$r['mahasiswa_id'])) {
                    throw new Exception('Anda tidak memiliki izin untuk membatalkan KRS mahasiswa ini.');
                }
                $reopen = [(int)$r['mahasiswa_id'], 'krs'];
                if ($r['nilai'] !== null) throw new Exception('Mata kuliah yang sudah bernilai tidak dapat dibatalkan.');
                q($pdo, 'DELETE FROM krs WHERE id=?', [(int)$_POST['krs_id']]);
                $flash = ['ok', $r['nama'] . ' dibatalkan dari KRS.'];

            } elseif ($act === 'nilai_set') {
                if ($role !== 'admin') throw new Exception('Hanya admin yang dapat mengubah nilai KHS.');
                $n = strtoupper(trim($_POST['nilai'] ?? ''));
                if ($n !== '' && !in_array($n, $NILAI, true)) throw new Exception('Nilai tidak valid.');
                $mid = (int)q($pdo, 'SELECT mahasiswa_id FROM krs WHERE id=?', [(int)($_POST['krs_id'] ?? 0)])->fetchColumn();
                if (!$mid) throw new Exception('Data KRS tidak ditemukan.');
                $reopen = [$mid, 'khs'];
                q($pdo, 'UPDATE krs SET nilai=? WHERE id=?', [$n === '' ? null : $n, (int)$_POST['krs_id']]);
                $flash = ['ok', 'Nilai disimpan.'];
            }
        }
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        if ($isForm === 'mhs' && $e->getCode() === '23000') $formErr = ['field' => 'nim', 'msg' => "NIM $nim sudah terdaftar. Gunakan NIM lain."];
        elseif ($isForm === 'dosen' && $e->getCode() === '23000') {
            $dupField = 'nama';
            foreach (array_keys($dosenCols) as $col) {
                if (stripos($e->getMessage(), $col) !== false) { $dupField = $col; break; }
            }
            $lbl = $DOSEN_LBL[$dupField] ?? ucfirst(str_replace('_', ' ', $dupField));
            $formErr = ['field' => $dupField, 'msg' => "$lbl sudah terdaftar. Gunakan yang lain."];
        }
        elseif ($e->getCode() === '23000') $flash = ['err', 'Data ini masih dipakai data lain, jadi belum bisa diubah atau dihapus.'];
        else { error_log('DB error: ' . $e->getMessage()); $flash = ['err', 'Terjadi kesalahan pada database. Coba lagi sebentar lagi.']; }
    } catch (FieldError $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $formErr = ['field' => $e->field, 'msg' => $e->getMessage()];
    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $flash = ['err', $e->getMessage()];
    }
    if ($formErr) {   // simpan isian supaya form terbuka lagi dengan data yang sama
        $oldData = $isForm === 'dosen'
            ? ($oldPost ?? ['nama' => trim($_POST['nama'] ?? ''), 'mk' => []])
            : ['nim' => $nim, 'nama' => trim($_POST['nama'] ?? ''), 'jk' => $_POST['jk'] ?? 'L',
               'kelas_id' => (int)($_POST['kelas_id'] ?? 0), 'email' => trim($_POST['email'] ?? ''), 'semester' => $_POST['semester'] ?? '', 'status' => $_POST['status'] ?? 'Aktif'];
        $_SESSION['formerr'] = $formErr + ['type' => $isForm, 'id' => $id, 'old' => $oldData];
        $flash = null;
    } else unset($_SESSION['formerr']);
    if ($flash) $_SESSION['flash'] = $flash;
    if ($reopen) $_SESSION['reopen'] = $reopen;
    if ($reopenTab) $_SESSION['tab'] = $reopenTab;
    header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?'));
    exit;
}
$flash = $_SESSION['flash'] ?? null; $reopen = $_SESSION['reopen'] ?? null; $reopenTab = $_SESSION['tab'] ?? null; $formErr = $_SESSION['formerr'] ?? null;
$loginOld = $_SESSION['login_old'] ?? '';
unset($_SESSION['flash'], $_SESSION['reopen'], $_SESSION['tab'], $_SESSION['formerr'], $_SESSION['login_old']);
$hero = asset_url('hero');

// ====== HALAMAN LOGIN JIKA BELUM LOGIN ======
if (!$USER):
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Login · Kelas <?= h($KELAS['nama']) ?> · <?= h($KELAS['prodi']) ?> · UNESA</title>
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<style>
:root{--bg:#f2f5fb;--card:#fff;--soft:#f6f8fd;--ink:#121d33;--mut:#5f6c85;--line:#dfe5f0;--pri:#0f3d91;--pri-d:#0a2c6b;--pri-t:#e4ecfb;--navy:#071a40;--gold:#f2b21c;--ok:#2b8a57;--ok-t:#dcf3e5;--err:#c53a3a;--err-t:#fbe3e3;--r:14px;--rs:9px}
*{box-sizing:border-box}body{margin:0;min-height:100vh;display:flex;flex-direction:column;font:14px/1.5 "Plus Jakarta Sans",system-ui,-apple-system,"Segoe UI",Roboto,sans-serif;color:var(--ink);background:linear-gradient(135deg,#071a40 0%,#0f3d91 60%,#1650b8 100%)}
.topbar{font-size:12.5px;color:#dbe6ff;background:rgba(7,26,64,.85);border-bottom:1px solid rgba(255,255,255,.1)}.topbar .in{max-width:1180px;margin:0 auto;padding:8px 20px}.topbar b{color:#fff;letter-spacing:.5px}
.wrap{flex:1;display:grid;place-items:center;padding:24px 16px}
.card{width:100%;max-width:430px;background:var(--card);border-radius:18px;border-top:5px solid var(--gold);box-shadow:0 24px 60px rgba(4,14,38,.45);overflow:hidden}
.head{padding:26px 28px 18px;border-bottom:1px solid var(--line)}
.badge{display:inline-block;padding:3px 11px;font-size:11.5px;font-weight:700;color:#3a2a00;background:var(--gold);border-radius:99px;margin-bottom:10px}
.head h1{margin:0;font-size:22px;font-weight:800;letter-spacing:-.4px;line-height:1.2}.head p{margin:6px 0 0;font-size:13.5px;color:var(--mut)}
form{padding:22px 28px 26px}
.fg{margin-bottom:15px}label{display:block;margin-bottom:5px;font-size:12.5px;font-weight:600;color:var(--mut)}
.pw-box{position:relative;display:flex;align-items:center}
input{width:100%;padding:10px 12px;font:inherit;color:var(--ink);background:#fff;border:1px solid var(--line);border-radius:var(--rs)}input:focus{outline:none;border-color:var(--pri);box-shadow:0 0 0 3px #dce7fc}
.pw-toggle{position:absolute;right:8px;padding:4px 8px;font-size:12px;font-weight:600;color:var(--mut);background:none;border:0;cursor:pointer}.pw-toggle:hover{color:var(--pri)}
.btn-pri{width:100%;padding:11px 16px;margin-top:4px;font:inherit;font-weight:700;color:#fff;background:var(--pri);border:0;border-radius:var(--rs);cursor:pointer}.btn-pri:hover{background:var(--pri-d)}
.alert{display:flex;align-items:flex-start;gap:10px;margin-bottom:16px;padding:10px 12px;font-size:13px;border-radius:10px}
.alert.err{color:#8f2323;background:var(--err-t);border-left:4px solid var(--err)}
.alert.ok{color:#1e633e;background:var(--ok-t);border-left:4px solid var(--ok)}
.ai{flex:none;display:grid;place-items:center;width:20px;height:20px;font-size:12px;font-weight:800;color:#fff;border-radius:50%}
.alert.err .ai{background:var(--err)}.alert.ok .ai{background:var(--ok)}
.demo{margin-top:18px;padding-top:14px;border-top:1px solid var(--line);font-size:12.5px;color:var(--mut)}
.demo summary{cursor:pointer;font-weight:600;color:var(--pri);user-select:none}
.demo-grid{display:grid;gap:6px;margin-top:10px}
.demo-item{display:flex;align-items:center;justify-content:space-between;gap:8px;padding:7px 10px;background:var(--soft);border:1px solid var(--line);border-radius:8px}
.demo-item b{color:var(--ink)}.demo-item button{padding:3px 9px;font-size:12px;font-weight:600;color:var(--pri-d);background:var(--pri-t);border:0;border-radius:6px;cursor:pointer}.demo-item button:hover{background:#cddcf6}
footer{padding:12px 16px 20px;text-align:center;font-size:12px;color:rgba(255,255,255,.75)}
</style>
</head>
<body>
<div class="topbar"><div class="in"><b><?= h(strtoupper($KELAS['kampus'])) ?></b> · <?= h($KELAS['fakultas']) ?> · <?= h($KELAS['gedung']) ?></div></div>
<div class="wrap">
  <div class="card">
    <div class="head">
      <span class="badge">PORTAL AKADEMIK <?= h($KELAS['nama']) ?></span>
      <h1>Masuk ke Dashboard</h1>
      <p><?= h($KELAS['prodi']) ?> · Tahun akademik <?= h($KELAS['tahun']) ?></p>
    </div>
    <form method="post">
      <input type="hidden" name="csrf" value="<?= h($_SESSION['csrf']) ?>">
      <input type="hidden" name="action" value="login">
      <?php if ($flash): ?>
        <div class="alert <?= $flash[0] === 'ok' ? 'ok' : 'err' ?>" role="alert">
          <span class="ai"><?= $flash[0] === 'ok' ? '✓' : '!' ?></span>
          <div><?= h($flash[1]) ?></div>
        </div>
      <?php endif; ?>
      <div class="fg">
        <label for="username">Username atau NIM</label>
        <input id="username" name="username" value="<?= h($loginOld) ?>" placeholder="Contoh: admin, dosen1, mhs1, atau NIM" required autofocus autocomplete="username">
      </div>
      <div class="fg">
        <label for="password">Password</label>
        <div class="pw-box">
          <input id="password" name="password" type="password" placeholder="Masukkan password" required autocomplete="current-password">
          <button type="button" class="pw-toggle" onclick="const p=document.getElementById('password');p.type=p.type==='password'?'text':'password';this.textContent=p.type==='password'?'Lihat':'Sembunyi'">Lihat</button>
        </div>
      </div>
      <button type="submit" class="btn-pri">Masuk</button>

      <details class="demo">
        <summary>Akun uji coba tiap kategori (Admin, Dosen, Mahasiswa)</summary>
        <div class="demo-grid">
          <div class="demo-item"><div><b>Admin</b> · <code>admin</code> / <code>123</code><br><small>Akses penuh edit mahasiswa &amp; dosen</small></div><button type="button" onclick="fillLogin('admin','123')">Isi</button></div>
          <div class="demo-item"><div><b>Dosen</b> · <code>dosen1</code> / <code>123</code><br><small>Hanya edit data dosen sendiri</small></div><button type="button" onclick="fillLogin('dosen1','123')">Isi</button></div>
          <div class="demo-item"><div><b>Mahasiswa</b> · <code>mhs1</code> / <code>123</code><br><small>Hanya edit data mahasiswa sendiri</small></div><button type="button" onclick="fillLogin('mhs1','123')">Isi</button></div>
        </div>
      </details>
    </form>
  </div>
</div>
<footer>Dashboard Kelas <?= h($KELAS['nama']) ?> · <?= h($KELAS['prodi']) ?> · <?= h($KELAS['kampus']) ?></footer>
<script>
function fillLogin(u, p){
  document.getElementById('username').value = u;
  document.getElementById('password').value = p;
  document.getElementById('username').focus();
}
</script>
</body>
</html>
<?php
exit;
endif;

// ====== QUERY JOIN (hanya dipakai di sisi server) ======
$TIME = "TIME_FORMAT(mk.jam_mulai,'%H:%i') AS mulai, TIME_FORMAT(mk.jam_selesai,'%H:%i') AS selesai";
$Q = [
    'mhs'     => "SELECT m.*, kl.nama AS kelas, kl.wali FROM mahasiswa m INNER JOIN kelas kl ON kl.id = m.kelas_id ORDER BY kl.nama, m.nim",
    'krs'     => "SELECT k.id AS krs_id, k.mahasiswa_id, k.nilai, mk.id AS mk_id, mk.kode, mk.nama, mk.sks, mk.semester AS smt,
                  mk.hari, $TIME, mk.ruang, d.nama AS dosen
                  FROM krs k JOIN matakuliah mk ON mk.id = k.matakuliah_id LEFT JOIN dosen d ON d.id = mk.dosen_id
                  ORDER BY k.mahasiswa_id, mk.semester, mk.kode",
    'mk'      => "SELECT mk.id, mk.kode, mk.nama, mk.sks, mk.semester AS smt, mk.hari, $TIME, mk.ruang, mk.kuota, mk.dosen_id,
                  d.nama AS dosen, COUNT(k.id) AS terisi
                  FROM matakuliah mk LEFT JOIN dosen d ON d.id = mk.dosen_id LEFT JOIN krs k ON k.matakuliah_id = mk.id
                  GROUP BY mk.id, d.nama ORDER BY mk.semester, mk.kode",
    'peserta' => "SELECT k.matakuliah_id, m.nim, m.nama, k.nilai FROM krs k JOIN mahasiswa m ON m.id = k.mahasiswa_id ORDER BY m.nim",
    'kelas'   => "SELECT kl.id, kl.nama, kl.angkatan, kl.wali, COUNT(m.id) AS jml FROM kelas kl LEFT JOIN mahasiswa m ON m.kelas_id = kl.id
                  GROUP BY kl.id, kl.nama, kl.angkatan, kl.wali ORDER BY kl.nama",
    'dosen'   => "SELECT d.* FROM dosen d ORDER BY d.nama",
];

// ====== AMBIL DATA (query JOIN) ======
$byMhs = $byMk = [];
foreach ($pdo->query($Q['krs']) as $r) {
    $r['nilai'] = $r['nilai'] ?? '';
    $r['dosen'] = trim((string)($r['dosen'] ?? ''));
    $byMhs[$r['mahasiswa_id']][] = ints($r, ['krs_id', 'mk_id', 'sks', 'smt']);
}
foreach ($pdo->query($Q['peserta']) as $r) { $r['nilai'] = $r['nilai'] ?? ''; $byMk[$r['matakuliah_id']][] = $r; }
$mhs = array_map(fn($r) => ints($r, ['id', 'kelas_id', 'semester']) + ['matkul' => $byMhs[$r['id']] ?? [], 'foto' => asset_url('foto/' . $r['nim'])], $pdo->query($Q['mhs'])->fetchAll());
$mkAll = array_map(function ($r) use ($byMk) {
    $r['dosen'] = trim((string)($r['dosen'] ?? ''));
    return ints($r, ['id', 'sks', 'smt', 'kuota', 'terisi', 'dosen_id']) + ['peserta' => $byMk[$r['id']] ?? [], 'img' => asset_url('mk/' . $r['kode'])];
}, $pdo->query($Q['mk'])->fetchAll());
$kelasAll = array_map(fn($r) => ints($r, ['id', 'jml']), $pdo->query($Q['kelas'])->fetchAll());
$dosenAll = array_map(function ($r) {
    $r['nama'] = trim((string)$r['nama']);
    return ints($r, ['id']);
}, $pdo->query($Q['dosen'])->fetchAll());
$json = fn($v) => json_encode($v, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE);
$roleTitles = ['admin' => 'Admin', 'dosen' => 'Dosen', 'mahasiswa' => 'Mahasiswa'];
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Kelas <?= h($KELAS['nama']) ?> · <?= h($KELAS['prodi']) ?> · UNESA</title>
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<style>
:root{--bg:#f2f5fb;--card:#fff;--soft:#f6f8fd;--ink:#121d33;--mut:#5f6c85;--line:#dfe5f0;--pri:#0f3d91;--pri-d:#0a2c6b;--pri-t:#e4ecfb;--navy:#071a40;--gold:#f2b21c;--gold-d:#8a5f00;--gold-t:#fff3d0;--ok:#2b8a57;--ok-t:#dcf3e5;--warn:#b97309;--warn-t:#fdf0d2;--err:#c53a3a;--err-t:#fbe3e3;--r:12px;--rs:9px}
*{box-sizing:border-box}[hidden]{display:none!important}html{scroll-behavior:smooth}body{margin:0;font:14px/1.5 "Plus Jakarta Sans",system-ui,-apple-system,"Segoe UI",Roboto,sans-serif;color:var(--ink);background:var(--bg)}h1,h2,h3,h4,p{margin:0}small{color:var(--mut)}:focus-visible{outline:3px solid #8fb0f0;outline-offset:1px}
label{display:block;margin-bottom:4px;font-size:12.5px;font-weight:600;color:var(--mut)}input,select{width:100%;padding:8px 11px;font:inherit;color:var(--ink);background:#fff;border:1px solid var(--line);border-radius:var(--rs)}input:focus,select:focus{border-color:var(--pri)}input:disabled,select:disabled{background:#f3f5fa;color:var(--mut);cursor:not-allowed}
button{padding:7px 13px;font:inherit;font-weight:600;color:var(--ink);background:#fff;border:1px solid var(--line);border-radius:var(--rs);cursor:pointer}button:hover{background:#eef2fa}
.btn-pri{color:#fff;background:var(--pri);border-color:var(--pri)}.btn-pri:hover{background:var(--pri-d)}.btn-pri:disabled{background:#9fb3dc;border-color:#9fb3dc;cursor:not-allowed}.btn-t{color:var(--pri-d);background:var(--pri-t);border-color:var(--pri-t)}.btn-t:hover{background:#cddcf6}
.btn-sm{padding:4px 10px;font-size:12.5px}.link-del{padding:4px 8px;font-size:12.5px;color:var(--err);background:none;border:0}.link-del:hover{background:var(--err-t)}.field .fe{display:none;margin-top:4px;font-size:12.5px;font-weight:600;color:var(--err)}.field label i{font-style:normal;color:var(--err)}
.field.invalid input,.field.invalid select{border-color:var(--err);background:#fff8f8}.field.invalid .fe{display:block}.badge{display:inline-block;padding:2px 10px;font-size:12px;font-weight:600;border-radius:99px}
.b-Aktif,.b-ok{color:var(--ok);background:var(--ok-t)}.b-Cuti,.b-warn{color:var(--warn);background:var(--warn-t)}.b-Nonaktif,.b-err{color:var(--err);background:var(--err-t)}.b-pri{color:var(--pri-d);background:var(--pri-t)}.b-kelas{color:var(--gold-d);background:var(--gold-t)}
.bar{flex:1;height:6px;overflow:hidden;background:#e3e9f5;border-radius:9px}.bar i{display:block;height:100%;background:var(--pri);border-radius:9px}.bar.full i{background:var(--err)}
.kchips{display:flex;flex-wrap:wrap;gap:8px;padding:14px 14px 0}.kchip{display:inline-flex;align-items:center;padding:5px 13px;font-size:13px;border-radius:99px}.kchip span{margin-left:7px;padding:0 7px;font-size:12px;color:var(--pri-d);background:var(--pri-t);border-radius:99px}
.kchip.on{color:#fff;background:var(--pri);border-color:var(--pri)}.kchip.on span{color:#fff;background:rgba(255,255,255,.22)}.kinfo{padding:8px 16px 0;font-size:13px;color:var(--mut)}.kinfo b{color:var(--ink)}
.alert{display:flex;align-items:flex-start;gap:10px;margin-bottom:14px;padding:11px 14px;font-size:13.5px;color:#8f2323;background:var(--err-t);border-left:4px solid var(--err);border-radius:10px}.alert b{display:block}
.ai,.ti{flex:none;display:grid;place-items:center;width:22px;height:22px;font-size:13px;font-weight:800;color:#fff;background:var(--err);border-radius:50%}
.toast{position:fixed;top:16px;left:50%;z-index:100;display:flex;align-items:flex-start;gap:10px;width:max-content;max-width:min(520px,calc(100vw - 28px));padding:12px 8px 12px 14px;background:#fff;border:1px solid var(--line);border-left:5px solid var(--err);border-radius:var(--r);box-shadow:0 12px 36px rgba(10,30,80,.28);opacity:0;pointer-events:none;transform:translate(-50%,-16px);transition:.2s}
.toast.show{opacity:1;pointer-events:auto;transform:translate(-50%,0)}.toast.ok{border-left-color:var(--ok)}.toast.ok .ti{background:var(--ok)}.toast b{display:block;line-height:1.3}.toast p{margin-top:2px;color:var(--mut)}.in{max-width:1180px;margin:0 auto;padding:7px 24px}
.topbar{font-size:12.5px;color:#dbe6ff;background:var(--navy)}.topbar .in{display:flex;flex-wrap:wrap;align-items:center;justify-content:space-between;gap:8px}.topbar b{color:#fff;letter-spacing:.5px}
.userbar{display:flex;align-items:center;gap:8px;flex-wrap:wrap}
.role-pill{padding:2px 9px;font-size:11.5px;font-weight:700;color:#3a2a00;background:var(--gold);border-radius:99px}
.btn-top{padding:3px 10px;font-size:12px;color:#fff;background:rgba(255,255,255,.14);border:1px solid rgba(255,255,255,.25);border-radius:7px}.btn-top:hover{background:rgba(255,255,255,.24)}
.btn-out{padding:3px 10px;font-size:12px;color:#ffd8d8;background:rgba(197,58,58,.28);border:1px solid rgba(255,140,140,.35);border-radius:7px}.btn-out:hover{background:rgba(197,58,58,.48);color:#fff}
.hero{color:#fff;background:linear-gradient(115deg,#091d47 10%,#12408f) center/cover;border-bottom:4px solid var(--gold)}
.hero .in{min-height:170px;padding:30px 24px 44px}.hero h1{font-size:30px;font-weight:800;letter-spacing:-.5px;line-height:1.15}.hero p{margin-top:8px;font-size:14.5px;color:#c9d8f5}
.meta{display:flex;flex-wrap:wrap;gap:8px;margin-top:16px}.chip{padding:4px 12px;font-size:12.5px;background:rgba(255,255,255,.12);border:1px solid rgba(255,255,255,.18);border-radius:99px}.chip:first-child{font-weight:700;color:#3a2a00;background:var(--gold);border-color:var(--gold)}
main{max-width:1180px;margin:0 auto;padding:0 24px 40px}.summary{display:flex;flex-wrap:wrap;margin-top:-26px;position:relative;background:var(--card);border:1px solid var(--line);border-radius:var(--r);box-shadow:0 8px 24px -14px rgba(10,30,80,.35)}
.sum-i{flex:1 1 150px;padding:14px 20px;border-right:1px solid var(--line)}.sum-i:last-child{border:0}.sum-i b{display:block;font-size:24px;font-weight:800;line-height:1.2}.sum-i small{font-size:12.5px}.tabs{display:flex;gap:4px;margin:24px 0 14px;overflow-x:auto;border-bottom:1px solid var(--line)}
.tab{flex:none;margin-bottom:-1px;padding:10px 16px;color:var(--mut);background:none;border:0;border-bottom:3px solid transparent;border-radius:0}.tab:hover{color:var(--ink);background:none}.tab.on{color:var(--pri);border-color:var(--gold)}
.tab span{margin-left:6px;padding:1px 8px;font-size:12px;color:var(--pri-d);background:var(--pri-t);border-radius:99px}.pane{display:none}.pane.on{display:block}.panel{overflow:hidden;background:var(--card);border:1px solid var(--line);border-radius:var(--r)}
.toolbar{display:flex;flex-wrap:wrap;align-items:center;gap:10px;padding:14px}.toolbar .sp{flex:1}.toolbar input,.toolbar select{width:auto;min-width:170px}.empty{padding:34px;text-align:center;color:var(--mut)}.empty-sm{color:var(--mut)}
.tw{overflow-x:auto}table{width:100%;border-collapse:collapse}th,td{padding:10px 14px;text-align:left;white-space:nowrap;border-top:1px solid var(--line)}th{font-size:12.5px;font-weight:600;color:var(--mut);background:var(--soft)}tbody tr:hover{background:#f8faff}
.num{text-align:center}.sub{display:block;font-size:12.5px;font-weight:400;color:var(--mut)}.act,.who{display:flex;align-items:center;gap:8px}.who{gap:11px}.who b{display:block;line-height:1.3}
.av,.pf-av{display:grid;place-items:center;flex:none;object-fit:cover;color:#fff;font-weight:700}.av{width:36px;height:36px;font-size:13px;border-radius:50%}.pf-av{width:96px;height:96px;font-size:32px;border-radius:20px}
.load{display:flex;align-items:center;gap:8px;min-width:120px}.tags{display:flex;flex-wrap:wrap;gap:4px;max-width:320px;white-space:normal}.nul{font-size:12px;font-style:italic;color:#99a4bd}
.mk-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(260px,1fr));gap:16px;padding:16px}.mk{display:flex;flex-direction:column;overflow:hidden;border:1px solid var(--line);border-radius:var(--r)}
.mk-img{display:grid;place-items:center;width:100%;aspect-ratio:16/8;object-fit:cover;font-size:26px;font-weight:800;letter-spacing:1px;color:rgba(255,255,255,.9)}
.mk-body{display:flex;flex:1;flex-direction:column;gap:6px;padding:12px 14px 14px}.mk-top{display:flex;align-items:center;justify-content:space-between;font-size:12.5px;color:var(--mut)}.mk h3{font-size:15px;line-height:1.3}
.mk-meta{display:grid;gap:2px;font-size:12.5px;color:var(--mut)}.mk-foot{display:flex;align-items:center;gap:10px;margin-top:auto;padding-top:8px}.ov{position:fixed;inset:0;z-index:50;display:none;align-items:flex-start;justify-content:center;padding:30px 14px;overflow:auto;background:rgba(14,28,34,.55)}.ov.show{display:flex}
.modal{width:100%;max-width:780px;background:#fff;border-radius:16px;box-shadow:0 24px 60px rgba(0,0,0,.3)}.mh{display:flex;align-items:center;justify-content:space-between;gap:12px;padding:15px 20px;border-bottom:1px solid var(--line)}.mh h2{font-size:17px}.mb{padding:18px 20px}
.mf{display:flex;align-items:center;justify-content:flex-end;gap:8px;padding:13px 20px;border-top:1px solid var(--line)}.mf .sp{flex:1;font-size:13px;color:var(--mut)}.x{padding:4px 9px;font-size:18px;line-height:1;color:var(--mut);background:none;border:0}
.grid{display:grid;grid-template-columns:1fr 1fr;gap:14px 12px}.grid .full{grid-column:1/-1}.doc-title{margin-bottom:12px;text-align:center}.doc-title h3{font-size:16px}.doc-title small{display:block}
.id-box{display:grid;grid-template-columns:auto 1fr auto 1fr;gap:4px 14px;margin-bottom:14px;font-size:13.5px}.id-box span{color:var(--mut)}.dtabs{display:flex;align-items:center;gap:8px;margin-bottom:12px}.dtabs label{margin:0}.dtabs select,select.nil{width:auto}select.nil{padding:3px 6px;font-weight:700}
.sum{display:flex;flex-wrap:wrap;gap:10px;margin-top:14px;font-weight:600}.sum div{padding:7px 14px;color:var(--pri-d);background:var(--pri-t);border-radius:var(--rs)}.only-print{display:none}
.take-info{display:flex;align-items:center;gap:12px;margin-bottom:12px;padding:12px 14px;background:var(--pri-t);border-radius:10px}.take-info .bar{background:#c6d6f3}.take-filter{display:flex;gap:8px;margin-bottom:10px}.take-filter select{width:auto}.take-list{display:grid;gap:8px;max-height:48vh;padding:2px;overflow:auto}
.opt{display:flex;align-items:flex-start;gap:12px;padding:10px 12px;border:1px solid var(--line);border-radius:11px;cursor:pointer}.opt:hover{border-color:#9db8ec}.opt.sel{border-color:var(--pri);background:#f3f7ff}
.opt.off{opacity:.55;background:#f6f8f8;cursor:not-allowed}.opt.bad{border-color:var(--err);background:#fff8f8}.opt input{width:auto;margin-top:3px;accent-color:var(--pri)}
.opt b{display:block;line-height:1.3}.o-main{flex:1;min-width:0}.o-meta{font-size:12.5px;color:var(--mut)}.o-warn{display:none;margin-top:2px;font-size:12.5px;font-weight:600;color:var(--err)}.opt.bad .o-warn{display:block}.o-sks{font-weight:700;white-space:nowrap}
.dsn-sec{margin-top:18px;padding-top:16px;border-top:1px solid var(--line)}.dsn-sec h4{margin-bottom:10px;font-size:14px}.dsn-list{max-height:34vh}
.role-note{margin-bottom:12px;padding:9px 12px;font-size:12.5px;color:var(--pri-d);background:var(--pri-t);border-radius:9px}
.pf-head{display:flex;align-items:center;gap:20px;margin-bottom:18px}.pf-head h3{font-size:22px;letter-spacing:-.3px;line-height:1.2}.pf-nim{margin:2px 0 10px;color:var(--mut)}
.pf-stats{display:grid;grid-template-columns:repeat(4,1fr);gap:10px;margin-bottom:20px}.pf-stat{padding:10px 14px;background:var(--pri-t);border-radius:10px}.pf-stat b{display:block;font-size:20px;font-weight:800;line-height:1.25;color:var(--pri-d)}
.pf-cols{display:grid;grid-template-columns:1fr 1fr;gap:24px;margin-bottom:20px}h4{margin-bottom:8px;font-size:13.5px}.pf-dl{display:grid;grid-template-columns:auto 1fr;gap:6px 16px;margin:0 0 18px}.pf-dl dt{color:var(--mut)}.pf-dl dd{margin:0;font-weight:600;overflow-wrap:anywhere}
.pf-list{display:grid;gap:8px;margin:0;padding:0;list-style:none}.pf-list li{padding:8px 12px;border:1px solid var(--line);border-radius:10px}.pf-list b{display:block;line-height:1.3}
.pf-hist{border:1px solid var(--line);border-radius:10px}.pf-hist tr:first-child th,.pf-hist tr:first-child td{border-top:0}.plist{max-height:50vh;overflow:auto}footer{padding:6px 0 24px;text-align:center;font-size:12.5px;color:var(--mut)}
@media(max-width:700px){main,.in{padding-left:14px;padding-right:14px}.hero h1{font-size:24px}.grid,.pf-cols{grid-template-columns:1fr}.id-box{grid-template-columns:auto 1fr}.sum-i{border-right:0;border-bottom:1px solid var(--line)}.pf-head{flex-direction:column;align-items:flex-start}.pf-stats{grid-template-columns:1fr 1fr}.toolbar input,.toolbar select,.take-filter{flex-wrap:wrap;flex:1;min-width:130px}}
@media print{body{background:#fff}body>*:not(#ov){display:none!important}#ov{position:static;display:block!important;padding:0;background:none}.modal{max-width:none!important;box-shadow:none}.no-print{display:none!important}.only-print{display:inline}}
</style>
</head>
<body>
<div class="topbar"><div class="in">
  <div><b><?= h(strtoupper($KELAS['kampus'])) ?></b> · <?= h($KELAS['fakultas']) ?> · <?= h($KELAS['gedung']) ?></div>
  <div class="userbar">
    <span class="role-pill"><?= h($roleTitles[$USER['role']] ?? $USER['role']) ?></span>
    <span><?= h($USER['nama']) ?></span>
    <?php if ($USER['role'] === 'mahasiswa' && $USER['mahasiswa_id']): ?>
      <button type="button" class="btn-top" onclick="openProfile(<?= (int)$USER['mahasiswa_id'] ?>)">Profil Saya</button>
    <?php elseif ($USER['role'] === 'dosen' && $USER['dosen_id']): ?>
      <button type="button" class="btn-top" onclick="showTab('dosen');openDosen(<?= (int)$USER['dosen_id'] ?>)">Profil Saya</button>
    <?php endif; ?>
    <button type="button" class="btn-out" onclick="if(confirm('Keluar dari akun ini?'))post({action:'logout'})">Keluar</button>
  </div>
</div></div>
<header class="hero"<?= $hero ? ' style="background-image:linear-gradient(90deg,rgba(8,28,72,.92) 15%,rgba(8,28,72,.35)),url(\'' . h($hero) . '\')"' : '' ?>><div class="in">
  <h1>Kelas <?= h($KELAS['nama']) ?><br><?= h($KELAS['prodi']) ?></h1>
  <p>Kelola data mahasiswa, ambil mata kuliah, dan pantau hasil studi.</p>
  <div class="meta"><span class="chip"><?= h($KELAS['gedung']) ?></span><span class="chip">Tahun akademik <?= h($KELAS['tahun']) ?></span>
    <span class="chip"><?= count($kelasAll) ?> kelas: <?= h(implode(', ', array_column($kelasAll, 'nama'))) ?></span></div>
</div></header>
<main>
  <section class="summary" id="stats"></section>
  <nav class="tabs">
    <button class="tab on" data-tab="mhs">Mahasiswa <span id="cMhs"></span></button>
    <button class="tab" data-tab="mk">Mata Kuliah <span id="cMk"></span></button>
    <button class="tab" data-tab="dosen">Data Dosen <span id="cDosen"></span></button>
  </nav>
  <section class="pane on" id="pane-mhs"><div class="panel">
    <div class="kchips" id="kChips"></div><div class="kinfo" id="kInfo"></div>
    <div class="toolbar">
      <input type="search" id="q" placeholder="Cari NIM atau nama" aria-label="Cari mahasiswa">
      <select id="fStatus" aria-label="Filter status"><option value="">Semua status</option><option>Aktif</option><option>Cuti</option><option>Nonaktif</option></select>
      <select id="fJk" aria-label="Filter jenis kelamin"><option value="">Semua</option><option value="L">Laki-laki</option><option value="P">Perempuan</option></select>
      <span class="sp"></span>
      <?php if ($USER['role'] === 'admin'): ?>
        <button class="btn-pri" onclick="openForm()">+ Tambah mahasiswa</button>
      <?php endif; ?>
    </div>
    <div class="tw"><table>
      <thead><tr><th>Mahasiswa</th><th>Kelas</th><th class="num">Smt</th><th>Status</th><th>SKS berjalan</th><th class="num">SKS lulus</th><th class="num">IPK</th><th>Aksi</th></tr></thead>
      <tbody id="tbody"></tbody></table></div>
    <div class="empty" id="empty" hidden>Tidak ada mahasiswa yang cocok. Ubah kata kunci atau filter.</div>
  </div></section>
  <section class="pane" id="pane-mk"><div class="panel">
    <div class="toolbar"><input type="search" id="qMk" placeholder="Cari kode, mata kuliah, atau dosen" aria-label="Cari mata kuliah"><select id="fSmt" aria-label="Filter semester"></select></div>
    <div class="mk-grid" id="mkGrid"></div><div class="empty" id="emptyMk" hidden>Tidak ada mata kuliah yang cocok.</div>
  </div></section>
  <section class="pane" id="pane-dosen"><div class="panel">
    <div class="toolbar"><input type="search" id="qDsn" placeholder="Cari dosen atau mata kuliah" aria-label="Cari dosen">
      <select id="fDsn" aria-label="Filter dosen"><option value="">Semua dosen</option><option value="wali">Dosen wali kelas</option><option value="ampu">Mengampu mata kuliah</option><option value="belum">Belum mengampu</option></select></div>
    <div class="tw"><table><thead id="dsnHead"></thead><tbody id="dsnBody"></tbody></table></div>
    <div class="empty" id="emptyDsn" hidden>Tidak ada dosen yang cocok.</div>
  </div></section>
</main>
<footer>Dashboard Kelas <?= h($KELAS['nama']) ?> · <?= h($KELAS['prodi']) ?> · <?= h($KELAS['kampus']) ?> · <?= h($KELAS['tahun']) ?></footer>

<div class="ov" id="ov"><div class="modal" id="mb"></div></div>   <!-- satu modal untuk semua dialog (diisi lewat JS) -->
<div class="toast" id="toast" role="status" aria-live="polite"><span class="ti" id="toastIcon"></span><div><b id="toastTitle"></b><p id="toastMsg"></p></div><button type="button" class="x" aria-label="Tutup" onclick="hideToast()">✕</button></div>

<script>
const AUTH = <?= $json($USER) ?>;
const DATA = <?= $json($mhs) ?>, MK = <?= $json($mkAll) ?>, DOSEN = <?= $json($dosenAll) ?>, DOSENCOLS = <?= $json($dosenCols) ?>, KELAS = <?= $json($KELAS) ?>, KELASLIST = <?= $json($kelasAll) ?>;
const MAXSKS = <?= (int)$MAX_SKS ?>, NILAI = <?= $json($NILAI) ?>, CSRF = <?= $json($_SESSION['csrf']) ?>;
const REOPEN = <?= $json($reopen) ?>, REOPENTAB = <?= $json($reopenTab) ?>, FLASH = <?= $json($flash) ?>, FORMERR = <?= $json($formErr) ?>, BOBOT = {A: 4, AB: 3.5, B: 3, BC: 2.5, C: 2, D: 1, E: 0};

// ====== HAK AKSES PER ROLE ======
const isAdmin = AUTH.role === 'admin';
const canEditMhs = id => isAdmin || (AUTH.role === 'mahasiswa' && AUTH.mahasiswa_id === id);
const canDelMhs = () => isAdmin;
const canEditKrs = id => isAdmin || (AUTH.role === 'mahasiswa' && AUTH.mahasiswa_id === id);
const canEditNilai = () => isAdmin;
const canEditDosen = id => isAdmin || (AUTH.role === 'dosen' && AUTH.dosen_id === id);

const $ = id => document.getElementById(id);
const esc = s => String(s ?? '').replace(/[&<>"']/g, c => ({'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'}[c]));
const fmt = n => n.toFixed(2), sum = (a, f) => a.reduce((t, x) => t + f(x), 0);
const mhsById = id => DATA.find(x => x.id === id), jadwal = x => x.hari ? `${x.hari}, ${x.mulai}–${x.selesai}` : '–', hue = s => [...String(s)].reduce((a, c) => (a * 31 + c.charCodeAt(0)) % 360, 7);
const post = d => {   // kirim form POST (logout, hapus, batal KRS, simpan nilai)
  const f = Object.assign(document.createElement('form'), {method: 'post'});
  Object.entries({csrf: CSRF, ...d}).forEach(([k, v]) => f.insertAdjacentHTML('beforeend', `<input type="hidden" name="${k}" value="${esc(v)}">`));
  document.body.append(f); f.submit();
};
const calc = l => { const g = l.filter(m => m.nilai in BOBOT), sks = sum(g, m => m.sks), mutu = sum(g, m => m.sks * BOBOT[m.nilai]); return {sks, ip: sks ? mutu / sks : 0}; };
const ipk = m => calc(m.matkul).ip, lulusSks = m => sum(m.matkul.filter(x => x.nilai && x.nilai !== 'E'), x => x.sks), beban = m => sum(m.matkul.filter(x => !x.nilai), x => x.sks), smtList = m => [...new Set(m.matkul.map(x => x.smt))].sort((a, b) => a - b);
const avatar = (m, big) => {   // foto asli (assets/foto/{NIM}.jpg) bila ada; jika tidak, inisial berwarna
  const c = big ? 'pf-av' : 'av';
  return m.foto ? `<img class="${c}" src="${esc(m.foto)}" alt="Foto ${esc(m.nama)}">`
    : `<span class="${c}" style="background:hsl(${hue(m.nim)} 45% 40%)">${esc(m.nama.split(' ').slice(0, 2).map(w => w[0]).join('').toUpperCase())}</span>`;
};
let KEEP = false, toastTimer;
const openM = (html, keep, w) => { $('mb').innerHTML = html; $('mb').style.maxWidth = w || ''; KEEP = !!keep; $('ov').classList.add('show'); };
const closeM = () => $('ov').classList.remove('show'), mh = t => `<div class="mh"><h2>${t}</h2><button type="button" class="x" aria-label="Tutup" onclick="closeM()">✕</button></div>`;
$('ov').addEventListener('mousedown', e => { if (e.target.id === 'ov' && !KEEP) closeM(); });
document.addEventListener('keydown', e => { if (e.key === 'Escape') closeM(); });
document.addEventListener('input', e => e.target.closest('.field')?.classList.remove('invalid'));
function showToast(type, msg){
  const ok = type === 'ok';
  $('toast').className = 'toast show ' + (ok ? 'ok' : 'err'); $('toastIcon').textContent = ok ? '✓' : '!';
  $('toastTitle').textContent = ok ? 'Berhasil' : 'Tidak bisa diproses'; $('toastMsg').textContent = msg;
  clearTimeout(toastTimer); toastTimer = setTimeout(hideToast, ok ? 4000 : 9000);
}
const hideToast = () => $('toast').classList.remove('show');
function renderStats(){
  const ips = DATA.map(ipk).filter(v => v > 0), L = DATA.filter(m => m.jk === 'L').length, aktif = DATA.filter(m => m.status === 'Aktif').length;
  const it = [
    [DATA.length, 'Mahasiswa', `${L} laki-laki · ${DATA.length - L} perempuan`],
    [KELASLIST.length, 'Kelas', KELASLIST.length ? `${KELASLIST[0].nama} s.d. ${KELASLIST.at(-1).nama}` : '–'],
    [aktif, 'Berstatus aktif', `${DATA.length - aktif} cuti / nonaktif`],
    [fmt(ips.length ? sum(ips, v => v) / ips.length : 0), 'Rata-rata IPK', 'dari mahasiswa yang sudah bernilai'],
    [MK.length, 'Mata kuliah dibuka', `${sum(MK, x => x.terisi)} pengambilan KRS`],
    [DOSEN.length, 'Dosen', `${DOSEN.filter(d => MK.some(x => x.dosen_id === d.id)).length} mengampu mata kuliah`]];
  $('stats').innerHTML = it.map(i => `<div class="sum-i"><b>${i[0]}</b><small>${i[1]} — ${i[2]}</small></div>`).join('');
  [['cMhs', DATA], ['cMk', MK], ['cDosen', DOSEN]].forEach(([i, a]) => $(i).textContent = a.length);
}
let KSEL = 0;   // id kelas terpilih; 0 = semua
function renderKelas(){
  $('kChips').innerHTML = [{id: 0, nama: 'Semua kelas', jml: DATA.length}, ...KELASLIST].map(c =>
    `<button type="button" class="kchip ${c.id === KSEL ? 'on' : ''}" onclick="pickKelas(${c.id})">${esc(c.nama)} <span>${c.jml}</span></button>`).join('');
  const k = KELASLIST.find(x => x.id === KSEL);
  $('kInfo').innerHTML = k ? `Dosen wali ${esc(k.nama)}: <b>${k.wali ? esc(k.wali) : '<i>belum diisi</i>'}</b> · Angkatan ${esc(k.angkatan)}` : 'Pilih kelas untuk melihat dosen wali dan daftar mahasiswanya.';
}
const pickKelas = id => { KSEL = id; renderKelas(); renderTable(); };
function renderTable(){
  const q = $('q').value.toLowerCase(), st = $('fStatus').value, jk = $('fJk').value;
  const list = DATA.filter(m => (!KSEL || m.kelas_id === KSEL) && (!q || (m.nim + m.nama).toLowerCase().includes(q)) && (!st || m.status === st) && (!jk || m.jk === jk));
  $('tbody').innerHTML = list.map(m => {
    const b = beban(m), mine = AUTH.role === 'mahasiswa' && AUTH.mahasiswa_id === m.id;
    return `<tr><td><div class="who">${avatar(m)}<div><b>${esc(m.nama)} ${mine ? '<span class="badge b-ok">Akun Anda</span>' : ''}</b><small>${esc(m.nim)}${m.email ? ' · ' + esc(m.email) : ''}</small></div></div></td>
      <td><span class="badge b-kelas">${esc(m.kelas)}</span></td><td class="num">${m.semester}</td><td><span class="badge b-${m.status}">${m.status}</span></td>
      <td><div class="load"><div class="bar ${b >= MAXSKS ? 'full' : ''}"><i style="width:${Math.min(100, b / MAXSKS * 100)}%"></i></div><small>${b}/${MAXSKS}</small></div></td>
      <td class="num">${lulusSks(m)}</td><td class="num"><b>${fmt(ipk(m))}</b></td>
      <td><div class="act"><button class="btn-sm btn-pri" onclick="openProfile(${m.id})">Detail</button><button class="btn-sm btn-t" onclick="openDoc(${m.id},'krs')">KRS</button><button class="btn-sm" onclick="openDoc(${m.id},'khs')">KHS</button></div></td></tr>`;
  }).join('');
  $('empty').hidden = !!list.length;
}
function renderMk(){
  const q = $('qMk').value.toLowerCase(), s = $('fSmt').value;
  const list = MK.filter(x => (!s || x.smt === +s) && (!q || `${x.kode} ${x.nama} ${x.dosen || ''}`.toLowerCase().includes(q)));
  $('mkGrid').innerHTML = list.map(x => {
    const full = x.terisi >= x.kuota, h = hue(x.kode.replace(/\d/g, ''));
    return `<article class="mk">${x.img ? `<img class="mk-img" src="${esc(x.img)}" alt="" loading="lazy">` : `<div class="mk-img" style="background:linear-gradient(135deg,hsl(${h} 60% 28%),hsl(${h} 55% 50%))">${esc(x.kode)}</div>`}
      <div class="mk-body"><div class="mk-top"><span>${esc(x.kode)} · Smt ${x.smt}</span><span class="badge ${full ? 'b-err' : 'b-ok'}">${full ? 'Penuh' : x.sks + ' SKS'}</span></div>
        <h3>${esc(x.nama)}</h3>
        <div class="mk-meta"><span>${esc(x.dosen || 'Dosen belum ditentukan')}</span><span>${x.hari ? jadwal(x) + (x.ruang ? ' · ' + esc(x.ruang) : '') : 'Jadwal belum ditentukan'}</span></div>
        <div class="mk-foot"><div class="bar ${full ? 'full' : ''}"><i style="width:${Math.min(100, x.terisi / x.kuota * 100)}%"></i></div><small>${x.terisi}/${x.kuota}</small><button class="btn-sm" onclick="openPeserta(${x.id})">Peserta</button></div></div></article>`;
  }).join('');
  $('emptyMk').hidden = !!list.length;
}
function openPeserta(id){
  const x = MK.find(m => m.id === id);
  openM(mh(`Peserta ${esc(x.nama)} (${x.terisi}/${x.kuota})`) + `<div class="plist tw">` + (x.peserta.length
    ? `<table><thead><tr><th>NIM</th><th>Nama</th><th class="num">Nilai</th></tr></thead><tbody>${x.peserta.map(p => `<tr><td>${esc(p.nim)}</td><td>${esc(p.nama)}</td><td class="num">${p.nilai || '–'}</td></tr>`).join('')}</tbody></table>`
    : '<div class="empty">Belum ada peserta.</div>') + `</div><div class="mf"><button class="btn-pri" onclick="closeM()">Tutup</button></div>`, false, '560px');
}
const LBL = <?= $json($DOSEN_LBL) ?>;
const dCols = Object.keys(DOSENCOLS);
const dLbl = k => LBL[k] || k.replace(/_/g, ' ').replace(/^./, c => c.toUpperCase());
const dMk = d => MK.filter(x => x.dosen_id === d.id), dWali = d => KELASLIST.filter(k => (k.wali || '').trim() === d.nama).map(k => k.nama);
const dVal = v => v == null || v === '' ? '<span class="nul">–</span>' : esc(v);
function renderDosen(){
  const q = $('qDsn').value.toLowerCase(), f = $('fDsn').value;
  const list = DOSEN.filter(d => (!q || (d.nama + dMk(d).map(x => ' ' + x.kode + ' ' + x.nama).join('')).toLowerCase().includes(q))
    && (!f || (f === 'wali' ? dWali(d).length : f === 'ampu' ? dMk(d).length : !dMk(d).length)));
  $('dsnHead').innerHTML = `<tr><th class="num">No</th><th>Dosen</th>${dCols.map(c => `<th>${esc(dLbl(c))}</th>`).join('')}<th>Mata kuliah diampu</th><th class="num">MK</th><th class="num">SKS</th><th>Aksi</th></tr>`;
  $('dsnBody').innerHTML = list.map((d, i) => {
    const mine = AUTH.role === 'dosen' && AUTH.dosen_id === d.id;
    return `<tr><td class="num">${i + 1}</td>
    <td><b>${esc(d.nama)} ${mine ? '<span class="badge b-ok">Akun Anda</span>' : ''}</b>${dWali(d).length ? `<span class="sub">Dosen wali ${esc(dWali(d).join(', '))}</span>` : ''}</td>${dCols.map(c => `<td>${dVal(d[c])}</td>`).join('')}
    <td><div class="tags">${dMk(d).map(x => `<span class="badge b-pri" title="${esc(x.nama)}">${esc(x.kode)}</span>`).join('') || '<span class="nul">Belum mengampu</span>'}</div></td>
    <td class="num">${dMk(d).length}</td><td class="num">${sum(dMk(d), x => x.sks)}</td><td><button class="btn-sm btn-pri" onclick="openDosen(${d.id})">Detail</button></td></tr>`;
  }).join('');
  $('emptyDsn').hidden = !!list.length;
}
function openDosen(id){
  const d = DOSEN.find(x => x.id === id), l = dMk(d), st = (v, k) => `<div class="pf-stat"><b>${v}</b><small>${k}</small></div>`;
  const editable = canEditDosen(id);
  openM(mh('Data dosen') + `<div class="mb"><h3 style="font-size:20px;margin-bottom:12px">${esc(d.nama)}</h3>
    <div class="pf-stats">${st(l.length, 'Mata kuliah')}${st(sum(l, x => x.sks), 'Total SKS')}${st(sum(l, x => x.terisi), 'Total peserta')}${st(esc(dWali(d).join(', ')) || '–', 'Dosen wali')}</div>
    ${dCols.length ? `<h4>Biodata</h4><dl class="pf-dl"><dt>Nama</dt><dd>${esc(d.nama)}</dd>${dCols.map(c => `<dt>${esc(dLbl(c))}</dt><dd>${dVal(d[c])}</dd>`).join('')}</dl>` : ''}
    <h4>Mata kuliah diampu</h4>${l.length ? `<div class="tw pf-hist"><table><thead><tr><th>Kode</th><th>Mata kuliah</th><th class="num">Smt</th><th class="num">SKS</th><th>Jadwal</th><th class="num">Peserta</th></tr></thead>
      <tbody>${l.map(x => `<tr><td>${esc(x.kode)}</td><td>${esc(x.nama)}</td><td class="num">${x.smt}</td><td class="num">${x.sks}</td><td>${jadwal(x)}${x.ruang ? `<span class="sub">${esc(x.ruang)}</span>` : ''}</td><td class="num">${x.terisi}</td></tr>`).join('')}</tbody></table></div>`
      : '<p class="empty-sm">Dosen ini belum mengampu mata kuliah.</p>'}</div>
    <div class="mf">${editable ? `<button onclick="openDosenForm(${id})">Edit</button>` : ''}<span class="sp"></span><button class="btn-pri" onclick="closeM()">Tutup</button></div>`);
}
function validateDosenForm(){
  const v = n => $('f_' + n).value.trim(), e = [];
  if (!v('nama')) e.push(['nama', 'Nama wajib diisi.']);
  dCols.forEach(c => {
    const val = v(c), meta = DOSENCOLS[c];
    if (meta.req && !val) e.push([c, `${dLbl(c)} wajib diisi.`]);
    else if (c.includes('email') && val && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(val)) e.push([c, 'Format email tidak valid.']);
  });
  if (e.length) { showFormErr(e); return false; }
  return true;
}
function updateDosenMkUI(dosenId){
  const smt = $('dsnTakeSmt').value, q = $('dsnTakeQ').value.toLowerCase(), view = $('dsnTakeView').value;
  let visible = 0, selCount = 0, selSks = 0;
  document.querySelectorAll('#dsnTakeList .opt').forEach(row => {
    const cb = row.querySelector('input'), mk = MK.find(x => x.id === +row.dataset.id);
    if (cb.checked) { selCount++; selSks += mk.sks; }
    row.classList.toggle('sel', cb.checked);
    const stEl = row.querySelector('.o-dsn');
    if (stEl) {
      if (mk.dosen_id === dosenId) {
        stEl.innerHTML = cb.checked
          ? '<span style="color:var(--ok);font-weight:600">Sedang diampu dosen ini</span>'
          : '<span style="color:var(--err);font-weight:600">Akan dilepas dari dosen ini</span>';
      } else if (mk.dosen_id) {
        stEl.innerHTML = `<span style="color:var(--warn)">Pengampu saat ini: <b>${esc(mk.dosen)}</b>${cb.checked ? ' → dipindahkan ke dosen ini' : ''}</span>`;
      } else {
        stEl.innerHTML = cb.checked
          ? '<span style="color:var(--pri-d);font-weight:600">Belum ada pengampu → akan diampu dosen ini</span>'
          : 'Belum ada dosen pengampu';
      }
    }
    const match = (!smt || mk.smt === +smt)
      && (!q || `${mk.kode} ${mk.nama} ${mk.dosen || ''}`.toLowerCase().includes(q))
      && (view !== 'sel' || cb.checked);
    row.hidden = !match;
    if (match) visible++;
  });
  $('dsnTakeEmpty').hidden = visible > 0;
  $('dsnMkTxt').textContent = `Mengampu: ${selCount} mata kuliah · ${selSks} SKS`;
}
function openDosenForm(id, old){
  if (!canEditDosen(id)) return;
  const d = DOSEN.find(x => x.id === id);
  if (!d) return;
  const o = old || Object.fromEntries(['nama', ...dCols].map(k => [k, d[k] ?? '']));
  const selectedMk = new Set((old && Array.isArray(old.mk) ? old.mk : dMk(d).map(x => x.id)).map(Number));
  const F = (n, label, inner, cls = '') => `<div class="field ${cls}"><label for="f_${n}">${label}</label>${inner}<small class="fe"></small></div>`;
  const inp = (n, extra = '') => `<input name="${n}" id="f_${n}" value="${esc(o[n])}" ${extra}>`;
  const sel = (n, opts) => `<select name="${n}" id="f_${n}">${opts.map(([v, t]) => `<option value="${esc(v)}" ${v == o[n] ? 'selected' : ''}>${esc(t)}</option>`).join('')}</select>`;
  const fields = dCols.map(c => {
    const meta = DOSENCOLS[c], lbl = esc(dLbl(c)) + (meta.req ? ' <i>*</i>' : '');
    if (meta.enum.length) {
      const opts = meta.enum.map(v => [v, (c === 'jk' || c === 'jenis_kelamin') ? (v === 'L' ? 'Laki-laki' : v === 'P' ? 'Perempuan' : v) : v]);
      return F(c, lbl, sel(c, meta.req ? opts : [['', '– Pilih –'], ...opts]));
    }
    const type = c.includes('email') ? 'type="email" placeholder="contoh: nama@unesa.ac.id"' : ['telepon', 'no_hp', 'hp'].includes(c) ? 'type="tel"' : '';
    return F(c, lbl, inp(c, `${type} maxlength="${meta.max}" autocomplete="off"`));
  }).join('');
  const smts = [...new Set(MK.map(x => x.smt))].sort((a, b) => a - b);
  const mkRows = MK.map(x => `<label class="opt" data-id="${x.id}">
    <input type="checkbox" name="mk[]" value="${x.id}" ${selectedMk.has(x.id) ? 'checked' : ''}>
    <div class="o-main"><b>${esc(x.nama)}</b>
      <div class="o-meta">${esc(x.kode)} · Smt ${x.smt} · ${jadwal(x)}${x.ruang ? ' · ' + esc(x.ruang) : ''}</div>
      <div class="o-meta o-dsn"></div>
    </div><span class="o-sks">${x.sks} SKS</span></label>`).join('');
  openM(`<form method="post" novalidate onsubmit="return validateDosenForm()">${mh('Edit dosen')}<div class="mb">
    <input type="hidden" name="csrf" value="${CSRF}"><input type="hidden" name="action" value="dosen_update"><input type="hidden" name="id" value="${id}">
    <div class="alert" id="formErr" role="alert" hidden><span class="ai">!</span><div><b>Data belum bisa disimpan</b><span id="formErrMsg"></span></div></div>
    <div class="grid">${F('nama', 'Nama lengkap <i>*</i>', inp('nama', 'maxlength="100" autocomplete="off"'), dCols.length % 2 === 0 ? 'full' : '')}${fields}</div>
    <div class="dsn-sec"><h4>Mata kuliah yang diampu</h4>
      <div class="take-info"><b id="dsnMkTxt">Mengampu: 0 mata kuliah · 0 SKS</b></div>
      <div class="take-filter">
        <select id="dsnTakeSmt" aria-label="Filter semester"><option value="">Semua semester</option>${smts.map(s => `<option value="${s}">Semester ${s}</option>`).join('')}</select>
        <select id="dsnTakeView" aria-label="Filter pilihan"><option value="">Semua mata kuliah</option><option value="sel">Dipilih saja</option></select>
        <input type="search" id="dsnTakeQ" placeholder="Cari kode atau mata kuliah" aria-label="Cari mata kuliah">
      </div>
      <div class="take-list dsn-list" id="dsnTakeList">${mkRows}<div class="empty" id="dsnTakeEmpty" hidden>Tidak ada mata kuliah yang cocok.</div></div>
    </div></div>
    <div class="mf"><button type="button" onclick="closeM()">Batal</button><button class="btn-pri">Simpan</button></div></form>`, true, '680px');
  $('dsnTakeSmt').onchange = $('dsnTakeView').onchange = $('dsnTakeQ').oninput = () => updateDosenMkUI(id);
  document.querySelectorAll('#dsnTakeList input[type="checkbox"]').forEach(cb => cb.onchange = () => updateDosenMkUI(id));
  updateDosenMkUI(id);
  if (!old) setTimeout(() => $('f_nama').focus(), 50);
}
function showFormErr(errs){   // errs: [[kolom, pesan], ...]
  document.querySelectorAll('.field.invalid').forEach(w => w.classList.remove('invalid'));
  errs.forEach(([n, msg]) => { const w = $('f_' + n)?.closest('.field'); if (w) { w.classList.add('invalid'); w.querySelector('.fe').textContent = msg; } });
  $('formErrMsg').textContent = errs.length > 1 ? `Periksa ${errs.length} isian yang ditandai merah.` : errs[0][1];
  $('formErr').hidden = false; $('f_' + errs[0][0])?.focus();
}
function validateForm(){
  const v = n => $('f_' + n).value.trim(), e = [], s = +v('semester');
  if (!v('nim')) e.push(['nim', 'NIM wajib diisi.']);
  if (!v('nama')) e.push(['nama', 'Nama wajib diisi.']);
  if (v('email') && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(v('email'))) e.push(['email', 'Format email tidak valid.']);
  if (!Number.isInteger(s) || s < 1 || s > 14) e.push(['semester', 'Semester harus antara 1 dan 14.']);
  if (e.length) { showFormErr(e); return false; }
  return true;
}
function openForm(id, old){   // old = isian yang dikembalikan server saat gagal simpan
  if (id ? !canEditMhs(id) : !isAdmin) return;
  const m = id ? mhsById(id) : null;
  const o = old || (m ? {...m, email: m.email || ''} : {nim: '', nama: '', jk: 'L', kelas_id: KSEL || KELASLIST[0]?.id, email: '', semester: 3, status: 'Aktif'});
  const selfMhs = !isAdmin && AUTH.role === 'mahasiswa';
  const lock = selfMhs ? 'disabled' : '';
  const F = (n, label, inner, cls = '') => `<div class="field ${cls}"><label for="f_${n}">${label}</label>${inner}<small class="fe"></small></div>`;
  const inp = (n, extra = '') => `<input name="${n}" id="f_${n}" value="${esc(o[n])}" ${extra}>`;
  const sel = (n, opts, extra = '') => `<select name="${n}" id="f_${n}" ${extra}>${opts.map(([v, t]) => `<option value="${esc(v)}" ${v == o[n] ? 'selected' : ''}>${esc(t)}</option>`).join('')}</select>`;
  openM(`<form method="post" novalidate onsubmit="return validateForm()">${mh(id ? 'Edit mahasiswa' : 'Tambah mahasiswa')}<div class="mb">
    <input type="hidden" name="csrf" value="${CSRF}"><input type="hidden" name="action" value="${id ? 'update' : 'create'}"><input type="hidden" name="id" value="${id || 0}">
    ${selfMhs ? '<div class="role-note">Anda login sebagai <b>Mahasiswa</b>. Anda dapat memperbarui nama, jenis kelamin, dan email; sedangkan NIM, kelas, semester, dan status dikelola oleh admin.</div>' : ''}
    <div class="alert" id="formErr" role="alert" hidden><span class="ai">!</span><div><b>Data belum bisa disimpan</b><span id="formErrMsg"></span></div></div>
    <div class="grid">${F('nim', 'NIM <i>*</i>', inp('nim', `maxlength="15" autocomplete="off" ${lock}`))}${F('nama', 'Nama lengkap <i>*</i>', inp('nama', 'maxlength="100" autocomplete="off"'))}
      ${F('jk', 'Jenis kelamin', sel('jk', [['L', 'Laki-laki'], ['P', 'Perempuan']]))}${F('kelas_id', 'Kelas', sel('kelas_id', KELASLIST.map(k => [k.id, k.nama]), lock))}
      ${F('email', 'Email', inp('email', 'type="email" maxlength="100" placeholder="contoh: nama@mhs.unesa.ac.id"'), 'full')}
      ${F('semester', 'Semester', inp('semester', `type="number" min="1" max="14" ${lock}`))}${F('status', 'Status', sel('status', ['Aktif', 'Cuti', 'Nonaktif'].map(s => [s, s]), lock))}</div></div>
    <div class="mf"><button type="button" onclick="closeM()">Batal</button><button class="btn-pri">Simpan</button></div></form>`, true, '560px');
  if (!old) setTimeout(() => $(selfMhs ? 'f_nama' : 'f_nim').focus(), 50);
}
const del = id => { if (!canDelMhs()) return; const m = mhsById(id); if (confirm(`Hapus ${m.nama} (${m.nim})? KRS dan nilainya ikut terhapus.`)) post({action: 'delete', id}); };
function openProfile(id){
  const m = mhsById(id), berjalan = m.matkul.filter(x => !x.nilai), kv = (k, v) => `<dt>${k}</dt><dd>${v || '–'}</dd>`, st = (v, k) => `<div class="pf-stat"><b>${v}</b><small>${k}</small></div>`;
  const editable = canEditMhs(id), deletable = canDelMhs();
  const riwayat = smtList(m).map(s => { const l = m.matkul.filter(x => x.smt === s), r = calc(l);
    return `<tr><td class="num">${s}</td><td class="num">${l.length}</td><td class="num">${sum(l, x => x.sks)}</td><td class="num">${r.sks}</td><td class="num"><b>${r.sks ? fmt(r.ip) : '–'}</b></td></tr>`; }).join('');
  openM(mh('Data mahasiswa') + `<div class="mb">
    <div class="pf-head">${avatar(m, true)}<div><h3>${esc(m.nama)}</h3><p class="pf-nim">${esc(m.nim)} · ${esc(m.kelas)} · ${esc(KELAS.prodi)}</p><span class="badge b-${m.status}">${m.status}</span> <span class="badge b-pri">Semester ${m.semester}</span></div></div>
    <div class="pf-stats">${st(fmt(ipk(m)), 'IPK')}${st(lulusSks(m), 'SKS lulus')}${st(beban(m) + ' / ' + MAXSKS, 'SKS berjalan')}${st(m.matkul.length, 'Mata kuliah diambil')}</div>
    <div class="pf-cols"><section><h4>Biodata</h4><dl class="pf-dl">${kv('NIM', esc(m.nim))}${kv('Nama', esc(m.nama))}${kv('Jenis kelamin', m.jk === 'L' ? 'Laki-laki' : 'Perempuan')}${kv('Email', esc(m.email))}${kv('Kelas', esc(m.kelas))}${kv('Program studi', esc(KELAS.prodi))}${kv('Dosen wali', esc(m.wali))}</dl></section>
      <section><h4>Mata kuliah berjalan</h4>${berjalan.length ? `<ul class="pf-list">${berjalan.map(x => `<li><b>${esc(x.nama)}</b><small>${esc(x.kode)} · ${x.sks} SKS · ${jadwal(x)}</small></li>`).join('')}</ul>` : '<p class="empty-sm">Belum ada mata kuliah yang sedang diambil.</p>'}</section></div>
    <h4>Riwayat per semester</h4>${riwayat ? `<div class="tw pf-hist"><table><thead><tr><th class="num">Smt</th><th class="num">MK</th><th class="num">SKS diambil</th><th class="num">SKS dinilai</th><th class="num">IPS</th></tr></thead><tbody>${riwayat}</tbody></table></div>` : '<p class="empty-sm">Belum ada riwayat studi.</p>'}</div>
    <div class="mf"><button class="btn-t" onclick="openDoc(${id},'krs')">KRS</button><button onclick="openDoc(${id},'khs')">KHS</button>${editable ? `<button onclick="openForm(${id})">Edit</button>` : ''}${deletable ? `<button class="link-del" onclick="closeM();del(${id})">Hapus</button>` : ''}<span class="sp"></span><button class="btn-pri" onclick="closeM()">Tutup</button></div>`);
}
function openDoc(id, type){
  const m = mhsById(id), aktif = m.status === 'Aktif', krs = type === 'krs', smts = smtList(m);
  const allowKrs = canEditKrs(id), allowNilai = canEditNilai();
  if (!smts.length) smts.push(m.semester);
  const graded = m.matkul.filter(x => x.nilai).map(x => x.smt);
  const sel = krs ? (smts.includes(m.semester) ? m.semester : smts.at(-1)) : (graded.length ? Math.max(...graded) : smts.at(-1));
  const draw = s => {
    const l = m.matkul.filter(x => x.smt === s);
    $('docContent').innerHTML = krs
      ? `<table><thead><tr><th class="num">No</th><th>Kode</th><th>Mata kuliah</th><th>Jadwal</th><th class="num">SKS</th><th class="no-print"></th></tr></thead><tbody>${l.map((x, i) =>
          `<tr><td class="num">${i + 1}</td><td>${esc(x.kode)}</td><td>${esc(x.nama)}<span class="sub">${esc(x.dosen || '')}</span></td><td>${jadwal(x)}${x.ruang ? `<span class="sub">${esc(x.ruang)}</span>` : ''}</td><td class="num">${x.sks}</td>
          <td class="no-print">${!x.nilai && aktif && allowKrs ? `<button class="link-del" onclick="dropKrs(${x.krs_id})">Batalkan</button>` : ''}</td></tr>`).join('') ||
          `<tr><td colspan="6" class="empty">Belum ada mata kuliah di semester ini.${aktif && allowKrs ? ' Klik “Ambil mata kuliah”.' : ''}</td></tr>`}</tbody></table>
        <div class="sum"><div>Total SKS semester ini: ${sum(l, x => x.sks)}</div><div>Beban berjalan: ${beban(m)} / ${MAXSKS} SKS</div></div>`
      : `<table><thead><tr><th class="num">No</th><th>Kode</th><th>Mata kuliah</th><th class="num">SKS</th><th class="num">Nilai</th><th class="num">Bobot</th><th class="num">Mutu</th></tr></thead><tbody>${l.map((x, i) => {
          const b = x.nilai in BOBOT ? BOBOT[x.nilai] : null;
          const nilCell = allowNilai
            ? `<select class="nil no-print" aria-label="Nilai ${esc(x.nama)}" onchange="post({action:'nilai_set',krs_id:${x.krs_id},nilai:this.value})">${['', ...NILAI].map(n => `<option value="${n}" ${n === x.nilai ? 'selected' : ''}>${n || '–'}</option>`).join('')}</select><b class="only-print">${x.nilai || '–'}</b>`
            : `<b>${x.nilai || '–'}</b>`;
          return `<tr><td class="num">${i + 1}</td><td>${esc(x.kode)}</td><td>${esc(x.nama)}</td><td class="num">${x.sks}</td>
            <td class="num">${nilCell}</td>
            <td class="num">${b === null ? '–' : fmt(b)}</td><td class="num">${b === null ? '–' : fmt(b * x.sks)}</td></tr>`; }).join('') || '<tr><td colspan="7" class="empty">Belum ada data.</td></tr>'}</tbody></table>
        <div class="sum"><div>SKS dinilai: ${calc(l).sks}</div><div>IPS: ${fmt(calc(l).ip)}</div><div>SKS kumulatif: ${calc(m.matkul.filter(x => x.smt <= s)).sks}</div><div>IPK: ${fmt(calc(m.matkul.filter(x => x.smt <= s)).ip)}</div></div>`;
  };
  const footHint = krs
    ? (!aktif ? `Status ${m.status}: tidak dapat mengambil mata kuliah.` : !allowKrs ? 'Mode lihat saja.' : '')
    : (allowNilai ? 'Ubah nilai lewat kolom Nilai; tersimpan otomatis.' : 'Mode lihat saja.');
  openM(`<div class="mh no-print"><h2>${krs ? 'Kartu Rencana Studi' : 'Kartu Hasil Studi'} — ${esc(m.nama)}</h2><button class="x" aria-label="Tutup" onclick="closeM()">✕</button></div><div class="mb">
    <div class="doc-title"><small>${esc(KELAS.kampus.toUpperCase())} · ${esc(KELAS.fakultas.toUpperCase())}</small><h3>${esc(KELAS.prodi.toUpperCase())}</h3><small>Tahun akademik ${esc(KELAS.tahun)}</small></div>
    <div class="id-box"><span>NIM</span><b>${esc(m.nim)}</b><span>Kelas</span><b>${esc(m.kelas)}</b><span>Nama</span><b>${esc(m.nama)}</b><span>Dosen wali</span><b>${esc(m.wali || '–')}</b></div>
    <div class="dtabs no-print"><label for="docSmt">Semester</label><select id="docSmt">${smts.map(s => `<option value="${s}" ${s === sel ? 'selected' : ''}>Semester ${s}</option>`).join('')}</select></div><div class="tw" id="docContent"></div></div>
    <div class="mf no-print"><span class="sp">${footHint}</span>
      ${krs && aktif && allowKrs ? `<button class="btn-t" onclick="openTake(${id})">+ Ambil mata kuliah</button>` : ''}<button onclick="window.print()">Cetak</button><button class="btn-pri" onclick="closeM()">Tutup</button></div>`);
  $('docSmt').onchange = e => draw(+e.target.value); draw(sel);
}
const dropKrs = id => { if (confirm('Batalkan mata kuliah ini dari KRS?')) post({action: 'krs_drop', krs_id: id}); };
let TAKE = null;   // mahasiswa yang sedang mengambil
function openTake(id){
  if (!canEditKrs(id)) return;
  const m = TAKE = mhsById(id), taken = new Set(m.matkul.map(x => x.mk_id));
  const smts = [...new Set(MK.filter(x => !taken.has(x.id)).map(x => x.smt))].sort((a, b) => a - b);
  openM(`<form method="post">${mh('Ambil mata kuliah — ' + esc(m.nama))}<div class="mb">
    <input type="hidden" name="csrf" value="${CSRF}"><input type="hidden" name="action" value="krs_add"><input type="hidden" name="mahasiswa_id" value="${m.id}">
    <div class="take-info"><b id="takeLoadTxt"></b><div class="bar" id="takeBar"><i></i></div></div>
    <div class="take-filter"><select id="takeSmt" aria-label="Filter semester"><option value="">Semua semester</option>${smts.map(s => `<option value="${s}">Semester ${s}</option>`).join('')}</select>
      <input type="search" id="takeQ" placeholder="Cari mata kuliah" aria-label="Cari mata kuliah"></div><div class="take-list" id="takeList"></div></div>
    <div class="mf"><span class="sp" id="takeSum">Belum ada yang dipilih</span><button type="button" onclick="closeM()">Batal</button><button class="btn-pri" id="takeSubmit" disabled>Simpan ke KRS</button></div></form>`, true);
  $('takeSmt').onchange = $('takeQ').oninput = drawTake; drawTake();
}
function drawTake(){
  const m = TAKE, taken = new Set(m.matkul.map(x => x.mk_id)), s = $('takeSmt').value, q = $('takeQ').value.toLowerCase();
  const checked = new Set([...document.querySelectorAll('#takeList input:checked')].map(i => +i.value));
  const list = MK.filter(x => !taken.has(x.id) && (!s || x.smt === +s) && (!q || (x.kode + ' ' + x.nama).toLowerCase().includes(q)));
  $('takeList').innerHTML = list.map(x => { const full = x.terisi >= x.kuota;
    return `<label class="opt ${full ? 'off' : ''}" data-id="${x.id}"><input type="checkbox" value="${x.id}" name="mk[]" ${full ? 'disabled' : ''} ${checked.has(x.id) ? 'checked' : ''}>
      <div class="o-main"><b>${esc(x.nama)}</b><div class="o-meta">${esc(x.kode)} · Smt ${x.smt} · ${esc(x.dosen || '–')}</div>
        <div class="o-meta">${jadwal(x)}${x.ruang ? ' · ' + esc(x.ruang) : ''} · ${full ? '<b style="color:var(--err)">Kuota penuh</b>' : 'sisa kuota ' + (x.kuota - x.terisi)}</div><div class="o-warn"></div></div><span class="o-sks">${x.sks} SKS</span></label>`; }).join('')
    || '<div class="empty">Tidak ada mata kuliah yang tersedia.</div>';
  document.querySelectorAll('#takeList input').forEach(i => i.onchange = checkTake); checkTake();
}
function checkTake(){
  const m = TAKE, base = m.matkul.filter(x => !x.nilai && x.hari), chosen = [...document.querySelectorAll('#takeList input:checked')].map(i => MK.find(x => x.id === +i.value));
  let bad = false;
  document.querySelectorAll('#takeList .opt').forEach(row => {
    const cb = row.querySelector('input'), mk = MK.find(x => x.id === +row.dataset.id); let msg = '';
    if (cb.checked && mk.hari) {
      const o = base.concat(chosen.filter(c => c !== mk)).find(o => o.hari === mk.hari && mk.mulai < o.selesai && o.mulai < mk.selesai);
      if (o) msg = `Bentrok dengan ${o.nama} (${o.hari} ${o.mulai}–${o.selesai})`;
    }
    row.classList.toggle('sel', cb.checked); row.classList.toggle('bad', !!msg); row.querySelector('.o-warn').textContent = msg; bad ||= !!msg;
  });
  const add = sum(chosen, x => x.sks), total = beban(m) + add, over = total > MAXSKS;
  $('takeLoadTxt').textContent = `Beban berjalan: ${total} / ${MAXSKS} SKS` + (add ? ` (+${add})` : '');
  $('takeBar').className = 'bar' + (over ? ' full' : ''); $('takeBar').firstElementChild.style.width = Math.min(100, total / MAXSKS * 100) + '%';
  $('takeSum').textContent = over ? `Melebihi batas ${MAXSKS} SKS` : chosen.length ? `${chosen.length} dipilih · ${add} SKS` : 'Belum ada yang dipilih';
  $('takeSum').style.color = over ? 'var(--err)' : ''; $('takeSubmit').disabled = !chosen.length || over || bad;
}
function showTab(t){
  document.querySelectorAll('.tab').forEach(b => b.classList.toggle('on', b.dataset.tab === t));
  document.querySelectorAll('.pane').forEach(p => p.classList.toggle('on', p.id === 'pane-' + t));
  history.replaceState(null, '', '#' + t);
}
document.querySelectorAll('.tab').forEach(b => b.onclick = () => showTab(b.dataset.tab));
['q', 'fStatus', 'fJk'].forEach(id => $(id).addEventListener('input', renderTable));
['qMk', 'fSmt'].forEach(id => $(id).addEventListener('input', renderMk));
['qDsn', 'fDsn'].forEach(id => $(id).addEventListener('input', renderDosen));
$('fSmt').innerHTML = '<option value="">Semua semester</option>' + [...new Set(MK.map(x => x.smt))].sort((a, b) => a - b).map(s => `<option value="${s}">Semester ${s}</option>`).join('');
renderStats(); renderKelas(); renderTable(); renderMk(); renderDosen();
if (REOPENTAB) showTab(REOPENTAB);
else if (['#mk', '#dosen'].includes(location.hash)) showTab(location.hash.slice(1));
if (REOPEN) openDoc(REOPEN[0], REOPEN[1]);
if (FLASH) showToast(FLASH[0], FLASH[1]);
if (FORMERR) {
  if (FORMERR.type === 'dosen' && canEditDosen(FORMERR.id)) { showTab('dosen'); openDosenForm(FORMERR.id, FORMERR.old); showFormErr([[FORMERR.field, FORMERR.msg]]); }
  else if (FORMERR.type === 'mhs' && (FORMERR.id ? canEditMhs(FORMERR.id) : isAdmin)) { openForm(FORMERR.id, FORMERR.old); showFormErr([[FORMERR.field, FORMERR.msg]]); }
}
</script>
</body>
</html>