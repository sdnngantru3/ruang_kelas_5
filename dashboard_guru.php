<?php
// dashboard_guru.php - Dashboard Guru (TEAL THEME + MULTI TAB + MONITORING + FIX AGAMA + MENU SOAL ONLINE)

// Pastikan file ini dipanggil oleh file induk dan koneksi sudah tersedia
if (!isset($koneksi)) { die('File ini tidak boleh diakses langsung.'); }

// =================================================================================
// 1. LOGIKA DATA (BACKEND)
// =================================================================================

// Ambil data aktif
$q_ta_aktif = mysqli_query($koneksi, "SELECT id_tahun_ajaran, tahun_ajaran FROM tahun_ajaran WHERE status = 'Aktif' LIMIT 1");
$data_tahun_aktif = mysqli_fetch_assoc($q_ta_aktif);
$id_tahun_ajaran_aktif = $data_tahun_aktif['id_tahun_ajaran'] ?? 0;
$nama_tahun_ajaran_aktif = $data_tahun_aktif['tahun_ajaran'] ?? 'Tidak Aktif';

$q_smt = mysqli_query($koneksi, "SELECT nilai_pengaturan FROM pengaturan WHERE nama_pengaturan = 'semester_aktif' LIMIT 1");
$semester_aktif = mysqli_fetch_assoc($q_smt)['nilai_pengaturan'] ?? 1;
$semester_text = ($semester_aktif == 1) ? 'Ganjil' : 'Genap';

// Mengambil data sesi guru
$id_guru_login = $_SESSION['id_guru'];
$nama_guru = $_SESSION['nama_guru'];

// Ambil foto profil guru
$query_foto = mysqli_query($koneksi, "SELECT foto_guru FROM guru WHERE id_guru = $id_guru_login");
$foto_profil_guru = 'assets/img/avatar/avatar-1.png'; 
if ($data_foto = mysqli_fetch_assoc($query_foto)) {
    $foto_filename = $data_foto['foto_guru'];
    $path_to_check = 'uploads/guru_photos/' . $foto_filename; 
    if (!empty($foto_filename) && file_exists($path_to_check)) {
        $foto_profil_guru = $path_to_check;
    }
}

// --- LOGIKA UTAMA: Cek Peran Wali Kelas & Mapel yang Diajar ---
$is_walas = false;
$id_kelas_wali = 0;
$nama_kelas_wali = '';
$mapel_diajar_walas = []; 
$mapel_diajar_lain = []; 
$mapel_diajar_lain_grouped = []; 

// 1. Cek apakah guru ini wali kelas
$query_walas = mysqli_prepare($koneksi, "SELECT id_kelas, nama_kelas FROM kelas WHERE id_wali_kelas = ? AND id_tahun_ajaran = ? LIMIT 1");
mysqli_stmt_bind_param($query_walas, "ii", $id_guru_login, $id_tahun_ajaran_aktif);
mysqli_stmt_execute($query_walas);
$result_walas = mysqli_stmt_get_result($query_walas);
if ($data_walas = mysqli_fetch_assoc($result_walas)) {
    $is_walas = true;
    $id_kelas_wali = $data_walas['id_kelas'];
    $nama_kelas_wali = $data_walas['nama_kelas'];
}
mysqli_stmt_close($query_walas);

// 2. Ambil SEMUA mapel yang diajar guru ini
$query_mengajar = mysqli_prepare($koneksi,
    "SELECT gm.id_mapel, mp.nama_mapel, gm.id_kelas, k.nama_kelas
     FROM guru_mengajar gm
     JOIN mata_pelajaran mp ON gm.id_mapel = mp.id_mapel
     JOIN kelas k ON gm.id_kelas = k.id_kelas
     WHERE gm.id_guru = ? AND gm.id_tahun_ajaran = ?
     ORDER BY k.nama_kelas, mp.urutan, mp.nama_mapel ASC"); 
mysqli_stmt_bind_param($query_mengajar, "ii", $id_guru_login, $id_tahun_ajaran_aktif);
mysqli_stmt_execute($query_mengajar);
$result_mengajar = mysqli_stmt_get_result($query_mengajar);

$is_pengampu = (mysqli_num_rows($result_mengajar) > 0); 

while ($row = mysqli_fetch_assoc($result_mengajar)) {
    $mapel_info = [
        'id_mapel' => $row['id_mapel'],
        'nama_mapel' => $row['nama_mapel'],
        'id_kelas' => $row['id_kelas'],
        'nama_kelas' => $row['nama_kelas']
    ];
    if ($is_walas && $row['id_kelas'] == $id_kelas_wali) {
        $mapel_diajar_walas[] = $mapel_info;
    } else {
        $mapel_diajar_lain[] = $mapel_info;
        $mapel_diajar_lain_grouped[$row['nama_mapel']][] = $mapel_info; 
    }
}
mysqli_stmt_close($query_mengajar);

// --- LOGIKA BARU: MONITORING INPUT NILAI (SEMUA KELAS AJAR) + FIX AGAMA ---
$data_monitoring_nilai = [];
$semua_mapel_ajar = array_merge($mapel_diajar_walas, $mapel_diajar_lain);

foreach ($semua_mapel_ajar as $mapel) {
    $id_k = $mapel['id_kelas'];
    $id_m = $mapel['id_mapel'];
    $nama_mapel_lower = strtolower($mapel['nama_mapel']);
    
    // [FIX LOGIKA AGAMA] Tentukan filter agama berdasarkan nama mapel
    $filter_agama = "";
    if (strpos($nama_mapel_lower, 'islam') !== false) {
        $filter_agama = "AND agama = 'Islam'";
    } elseif (strpos($nama_mapel_lower, 'kristen') !== false) {
        $filter_agama = "AND agama = 'Kristen'";
    } elseif (strpos($nama_mapel_lower, 'katolik') !== false) {
        $filter_agama = "AND agama = 'Katolik'";
    } elseif (strpos($nama_mapel_lower, 'hindu') !== false) {
        $filter_agama = "AND agama = 'Hindu'";
    } elseif (strpos($nama_mapel_lower, 'buddha') !== false || strpos($nama_mapel_lower, 'budha') !== false) {
        $filter_agama = "AND (agama = 'Buddha' OR agama = 'Budha')";
    } elseif (strpos($nama_mapel_lower, 'khonghucu') !== false) {
        $filter_agama = "AND agama = 'Khonghucu'";
    }

    // 1. Hitung Jumlah Siswa Target (Sesuai Agama jika Mapel Agama)
    $q_s = mysqli_query($koneksi, "SELECT COUNT(*) as t FROM siswa WHERE id_kelas=$id_k AND status_siswa='Aktif' $filter_agama");
    $j_s = mysqli_fetch_assoc($q_s)['t'];
    
    // 2. Hitung Asesmen Sumatif yg dibuat Guru untuk Mapel & Kelas ini
    $q_a = mysqli_query($koneksi, "SELECT COUNT(*) as t FROM penilaian WHERE id_kelas=$id_k AND id_mapel=$id_m AND semester=$semester_aktif AND jenis_penilaian='Sumatif'");
    $j_a = mysqli_fetch_assoc($q_a)['t'];
    
    // 3. Hitung Detail Nilai yg Masuk (Hanya dari siswa yang sesuai target)
    // Kita join ke tabel siswa untuk memfilter agama juga di sini agar akurat
    $q_n = mysqli_query($koneksi, "
        SELECT COUNT(pdn.id_detail_nilai) as t 
        FROM penilaian_detail_nilai pdn 
        JOIN penilaian p ON pdn.id_penilaian = p.id_penilaian 
        JOIN siswa s ON pdn.id_siswa = s.id_siswa
        WHERE p.id_kelas=$id_k 
          AND p.id_mapel=$id_m 
          AND p.semester=$semester_aktif 
          AND p.jenis_penilaian='Sumatif'
          AND s.status_siswa='Aktif'
          $filter_agama
    ");
    $j_n = mysqli_fetch_assoc($q_n)['t'];
    
    // 4. Hitung Persentase
    // Target Total = (Jml Siswa Sesuai Agama) * (Jml Asesmen)
    $target = $j_s * $j_a;
    $persen = 0;
    $status_badge = 'bg-secondary';
    $status_text = 'Belum Ada Data';
    $progress_color = 'bg-secondary';

    // Jika tidak ada siswa yang sesuai agama (misal guru Kristen ngajar di kelas 100% Muslim), 
    // anggap selesai (N/A) atau 100% agar tidak merah.
    if ($j_s == 0) {
        $status_badge = 'bg-success-soft text-success'; // Hijau soft
        $status_text = 'Tidak Ada Siswa';
        $progress_color = 'bg-success'; // Penuh
        $persen = 100;
    } 
    elseif ($j_a == 0) {
        $status_badge = 'bg-danger-soft text-danger';
        $status_text = 'Belum Ada Asesmen';
        $progress_color = 'bg-danger';
        $persen = 0;
    } else {
        $persen = ($target > 0) ? round(($j_n / $target) * 100) : 0;
        
        if ($persen >= 100) {
            $status_badge = 'bg-success-soft text-success';
            $status_text = 'Lengkap';
            $progress_color = 'bg-success';
        } elseif ($persen > 0) {
            $status_badge = 'bg-warning-soft text-warning';
            $status_text = 'Proses Input';
            $progress_color = 'bg-warning';
        } else {
            $status_badge = 'bg-secondary-soft text-secondary';
            $status_text = 'Nilai Kosong';
            $progress_color = 'bg-secondary';
        }
    }
    
    $data_monitoring_nilai[] = [
        'nama_mapel' => $mapel['nama_mapel'],
        'nama_kelas' => $mapel['nama_kelas'],
        'jml_asesmen' => $j_a,
        'jml_siswa_target' => $j_s, // Info tambahan debug
        'persen' => $persen,
        'badge' => $status_badge,
        'text' => $status_text,
        'pg_color' => $progress_color,
        'link_input' => "penilaian_tampil.php?id_kelas=$id_k&id_mapel=$id_m"
    ];
}


// --- LOGIKA DATA UNTUK WALI KELAS ---
$jumlah_siswa_walas = 0;
$jumlah_rapor_final_walas = 0;
$progres_rapor_walas = 0;
$siswa_absen_tinggi = 0; 
$data_belum_lengkap = 0; 
$batas_absen = 10; 

if ($is_walas) {
    // Jumlah siswa
    $query_siswa_walas = mysqli_prepare($koneksi, "SELECT COUNT(id_siswa) as total_siswa FROM siswa WHERE id_kelas = ? AND status_siswa = 'Aktif'");
    mysqli_stmt_bind_param($query_siswa_walas, "i", $id_kelas_wali);
    mysqli_stmt_execute($query_siswa_walas);
    $jumlah_siswa_walas = mysqli_fetch_assoc(mysqli_stmt_get_result($query_siswa_walas))['total_siswa'] ?? 0;
    mysqli_stmt_close($query_siswa_walas);

    // Jumlah rapor final & progres
    $query_rapor_final_walas = mysqli_prepare($koneksi, "SELECT COUNT(id_rapor) as total_final FROM rapor WHERE id_kelas = ? AND status = 'Final' AND semester = ? AND id_tahun_ajaran = ?");
    mysqli_stmt_bind_param($query_rapor_final_walas, "iii", $id_kelas_wali, $semester_aktif, $id_tahun_ajaran_aktif);
    mysqli_stmt_execute($query_rapor_final_walas);
    $jumlah_rapor_final_walas = mysqli_fetch_assoc(mysqli_stmt_get_result($query_rapor_final_walas))['total_final'] ?? 0;
    mysqli_stmt_close($query_rapor_final_walas);
    $progres_rapor_walas = ($jumlah_siswa_walas > 0) ? round(($jumlah_rapor_final_walas / $jumlah_siswa_walas) * 100) : 0;

    // Hitung siswa absen tinggi
    $query_absen = mysqli_prepare($koneksi, "SELECT COUNT(id_rapor) as total_absen_tinggi FROM rapor WHERE id_kelas = ? AND semester = ? AND id_tahun_ajaran = ? AND (sakit + izin + tanpa_keterangan) > ?");
    mysqli_stmt_bind_param($query_absen, "iiii", $id_kelas_wali, $semester_aktif, $id_tahun_ajaran_aktif, $batas_absen);
    mysqli_stmt_execute($query_absen);
    $siswa_absen_tinggi = mysqli_fetch_assoc(mysqli_stmt_get_result($query_absen))['total_absen_tinggi'] ?? 0;
    mysqli_stmt_close($query_absen);

    // Hitung data belum lengkap
    $query_data_lengkap = mysqli_prepare($koneksi, "SELECT COUNT(id_rapor) as total_belum_lengkap FROM rapor WHERE id_kelas = ? AND semester = ? AND id_tahun_ajaran = ? AND (catatan_wali_kelas IS NULL OR catatan_wali_kelas = '')");
    mysqli_stmt_bind_param($query_data_lengkap, "iii", $id_kelas_wali, $semester_aktif, $id_tahun_ajaran_aktif);
    mysqli_stmt_execute($query_data_lengkap);
    $data_belum_lengkap = mysqli_fetch_assoc(mysqli_stmt_get_result($query_data_lengkap))['total_belum_lengkap'] ?? 0;
    mysqli_stmt_close($query_data_lengkap);
}
?>

<!-- =================================================================================
     2. TAMPILAN MODERN (TEAL THEME & MULTI TAB)
================================================================================== -->
<style>
    /* Menggunakan variabel warna agar konsisten */
    :root {
        --primary-teal: #0d9488;
        --secondary-teal: #14b8a6;
        --light-teal: #f0fdfa;
        --accent-teal: #5eead4;
        --text-dark: #134e4a;
        --card-radius: 20px;
        --shadow-card: 0 10px 30px -5px rgba(0,0,0,0.05);
    }
    
    /* Layout utama wrapper agar tidak overflow */
    .dashboard-container {
        padding-top: 1.5rem;
        padding-bottom: 3rem; /* Memberi ruang di bawah agar tidak menutupi footer */
        max-width: 100%;
        overflow-x: hidden;
    }
    
    body {
        background-color: #f8fafc;
        font-family: 'Poppins', sans-serif;
        color: #334155;
    }

    /* HERO HEADER */
    .hero-teacher {
        background: linear-gradient(135deg, var(--primary-teal) 0%, var(--secondary-teal) 100%);
        border-radius: 24px;
        padding: 3rem 2rem;
        color: white;
        position: relative;
        overflow: hidden;
        box-shadow: 0 15px 35px -10px rgba(20, 184, 166, 0.4);
        margin-bottom: 2rem;
    }
    .profile-img-lg {
        width: 100px; height: 100px; border-radius: 50%;
        border: 4px solid rgba(255,255,255,0.4); object-fit: cover; background: #fff;
        box-shadow: 0 5px 15px rgba(0,0,0,0.2);
    }
    .role-badge {
        background: rgba(255,255,255,0.2); backdrop-filter: blur(8px);
        padding: 8px 16px; border-radius: 30px; font-size: 0.85rem;
        border: 1px solid rgba(255,255,255,0.3); display: inline-flex; align-items: center; gap: 8px;
    }

    /* MODERN TABS */
    .nav-pills-modern {
        background: white;
        padding: 0.5rem;
        border-radius: 50px;
        box-shadow: 0 4px 6px -1px rgba(0,0,0,0.05);
        display: inline-flex;
        margin-bottom: 2rem;
    }
    .nav-pills-modern .nav-link {
        border-radius: 30px;
        color: #64748b;
        padding: 10px 24px;
        font-weight: 600;
        transition: all 0.3s ease;
    }
    .nav-pills-modern .nav-link.active {
        background: var(--primary-teal);
        color: white;
        box-shadow: 0 4px 10px rgba(13, 148, 136, 0.3);
    }
    .nav-pills-modern .nav-link:hover:not(.active) {
        background: var(--light-teal);
        color: var(--text-dark);
    }

    /* CARDS */
    .dash-card {
        background: white; border-radius: var(--card-radius);
        border: none; box-shadow: var(--shadow-card);
        height: 100%; overflow: hidden;
        transition: transform 0.2s ease;
    }
    .dash-card:hover { transform: translateY(-5px); }
    
    .card-header-modern {
        padding: 1.5rem; border-bottom: 1px solid #f1f5f9;
        display: flex; justify-content: space-between; align-items: center;
    }
    .card-body-modern { padding: 1.5rem; }

    /* STAT BOXES */
    .stat-box-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(140px, 1fr)); gap: 1rem; }
    .stat-box {
        background: var(--light-teal); border-radius: 16px; padding: 1.5rem; text-align: center;
        border: 1px solid transparent; transition: all 0.2s;
    }
    .stat-box:hover { background: #ccfbf1; border-color: var(--accent-teal); }
    .stat-num { font-size: 2.2rem; font-weight: 800; line-height: 1; color: var(--primary-teal); margin-bottom: 5px; }
    .stat-lbl { font-size: 0.8rem; text-transform: uppercase; font-weight: 700; color: #64748b; letter-spacing: 0.5px; }

    /* MONITORING ITEMS */
    .monitoring-item {
        background: #fff; border-radius: 12px; padding: 1rem; margin-bottom: 1rem;
        border: 1px solid #e2e8f0; transition: all 0.2s;
    }
    .monitoring-item:hover { border-color: var(--primary-teal); box-shadow: 0 4px 10px rgba(0,0,0,0.03); }
    
    .progress-thin { height: 6px; border-radius: 10px; background: #e2e8f0; margin-top: 8px; overflow: hidden; }
    .progress-bar-fill { height: 100%; transition: width 0.6s ease; border-radius: 10px; }

    /* UTILS */
    .btn-action-soft {
        padding: 8px 16px; border-radius: 12px; font-size: 0.85rem; font-weight: 600;
        text-decoration: none; transition: all 0.2s; display: inline-flex; align-items: center; gap: 6px;
    }
    .bg-teal-soft { background: #ccfbf1; color: #0f766e; }
    .bg-teal-soft:hover { background: #0f766e; color: white; }
    
    .text-teal { color: var(--primary-teal) !important; }
    .hover-up { transition: all 0.2s ease; }
    .hover-up:hover { transform: translateY(-2px); box-shadow: 0 8px 20px rgba(0,0,0,0.15) !important; }
</style>

<div class="container-fluid dashboard-container">
    
    <!-- HERO HEADER -->
    <div class="hero-teacher">
        <div class="row align-items-center">
            <div class="col-lg-8 col-md-7 d-flex align-items-center">
                <img src="<?php echo htmlspecialchars($foto_profil_guru); ?>" alt="Profile" class="profile-img-lg me-4 shadow-sm">
                <div>
                    <h2 class="mb-1 fw-bold">Halo, <?php echo htmlspecialchars($nama_guru); ?>!</h2>
                    <p class="mb-2 opacity-90">Selamat datang di Dashboard Akademik Guru.</p>
                    <div class="d-flex flex-wrap gap-2">
                        <span class="role-badge"><i class="bi bi-calendar-check"></i> T.A. <?php echo $nama_tahun_ajaran_aktif; ?> (<?php echo $semester_text; ?>)</span>
                        <?php if ($is_walas): ?>
                            <span class="role-badge bg-white text-dark fw-bold" style="color: #0f766e;"><i class="bi bi-person-workspace"></i> Wali Kelas <?php echo htmlspecialchars($nama_kelas_wali); ?></span>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <!-- Quick Action: Masuk Ruang Kelas -->
            <div class="col-lg-4 col-md-5 text-md-end text-start mt-3 mt-md-0">
                <a href="https://sdnngantru3.github.io/ruang_kelas_5" target="_blank" class="btn btn-light text-teal fw-bold px-4 py-2.5 rounded-pill shadow-sm hover-up">
                    <i class="bi bi-pencil-square me-2"></i> Masuk Ruang Kelas <i class="bi bi-box-arrow-up-right small ms-1"></i>
                </a>
            </div>
        </div>
    </div>

    <!-- TAB NAVIGATION -->
    <div class="text-center">
        <div class="nav nav-pills-modern align-items-center" id="pills-tab" role="tablist">
            <?php if ($is_walas): ?>
            <button class="nav-link active" id="pills-walas-tab" data-bs-toggle="pill" data-bs-target="#pills-walas" type="button" role="tab">
                <i class="bi bi-house-door-fill me-2"></i>Wali Kelas
            </button>
            <?php endif; ?>
            
            <button class="nav-link <?php echo (!$is_walas) ? 'active' : ''; ?>" id="pills-mapel-tab" data-bs-toggle="pill" data-bs-target="#pills-mapel" type="button" role="tab">
                <i class="bi bi-journal-text me-2"></i>Guru Mapel
            </button>
            
            <button class="nav-link me-2" id="pills-guide-tab" data-bs-toggle="pill" data-bs-target="#pills-guide" type="button" role="tab">
                <i class="bi bi-info-circle-fill me-2"></i>Panduan
            </button>
            
            <!-- Menu Khusus Buat Soal Online (Membuka Tab Baru) -->
            <a href="https://sdnngantru3.github.io/web-sekolah/latihan_soal.html" target="_blank" class="nav-link text-white hover-up" style="background: linear-gradient(135deg, #f59e0b 0%, #d97706 100%); border-radius: 30px; font-weight: 600; box-shadow: 0 4px 12px rgba(217, 119, 6, 0.3);">
                <i class="bi bi-laptop me-2"></i>Buat Soal Online <i class="bi bi-box-arrow-up-right small ms-1"></i>
            </a>
        </div>
    </div>

    <!-- TAB CONTENT -->
    <div class="tab-content" id="pills-tabContent">
        
        <!-- TAB 1: WALI KELAS -->
        <?php if ($is_walas): ?>
        <div class="tab-pane fade show active" id="pills-walas" role="tabpanel">
            <div class="row g-4">
                <div class="col-lg-8">
                    <div class="dash-card">
                        <div class="card-header-modern">
                            <h5 class="mb-0 fw-bold text-dark">Statistik Kelas <?php echo htmlspecialchars($nama_kelas_wali); ?></h5>
                            <a href="walikelas_data_rapor.php" class="btn-action-soft bg-teal-soft">Input Data Rapor <i class="bi bi-arrow-right"></i></a>
                        </div>
                        <div class="card-body-modern">
                            <!-- Stat Boxes -->
                            <div class="stat-box-grid mb-4">
                                <div class="stat-box">
                                    <div class="stat-num"><?php echo $jumlah_siswa_walas; ?></div>
                                    <span class="stat-lbl">Siswa</span>
                                </div>
                                <div class="stat-box" style="background: #fef2f2;">
                                    <div class="stat-num text-danger"><?php echo $siswa_absen_tinggi; ?></div>
                                    <span class="stat-lbl text-danger">Absen > <?php echo $batas_absen; ?></span>
                                </div>
                                <div class="stat-box" style="background: #fffbeb;">
                                    <div class="stat-num text-warning"><?php echo $data_belum_lengkap; ?></div>
                                    <span class="stat-lbl text-warning">Data Kurang</span>
                                </div>
                            </div>
                            
                            <!-- Chart & Finalisasi -->
                            <div class="bg-light p-4 rounded-4 d-flex align-items-center flex-wrap gap-4">
                                <div style="width: 120px; height: 120px; flex-shrink: 0;">
                                    <canvas id="walasRaporChart"></canvas>
                                </div>
                                <div class="flex-grow-1">
                                    <h5 class="fw-bold mb-1">Status Finalisasi Rapor</h5>
                                    <p class="text-muted mb-3">
                                        Sudah <strong><?php echo $jumlah_rapor_final_walas; ?></strong> dari <strong><?php echo $jumlah_siswa_walas; ?></strong> siswa difinalisasi.
                                        Pastikan semua nilai mapel sudah masuk sebelum finalisasi.
                                    </p>
                                    <div class="d-flex gap-2">
                                        <a href="walikelas_proses_rapor.php" class="btn btn-outline-secondary rounded-pill px-4">Monitor Nilai</a>
                                        <a href="walikelas_cetak_rapor.php" class="btn btn-success rounded-pill px-4">Finalisasi & Cetak</a>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                
                <div class="col-lg-4">
                    <div class="dash-card">
                        <div class="card-header-modern">
                            <h6 class="mb-0 fw-bold">Mapel Ajar di Kelas Ini</h6>
                        </div>
                        <div class="card-body-modern p-0">
                            <?php if (!empty($mapel_diajar_walas)): ?>
                                <div class="list-group list-group-flush">
                                <?php foreach ($mapel_diajar_walas as $mapel): ?>
                                    <div class="list-group-item border-0 border-bottom p-3">
                                        <div class="fw-bold text-dark mb-1"><?php echo htmlspecialchars($mapel['nama_mapel']); ?></div>
                                        <div class="d-flex gap-2">
                                            <a href="tp_guru_tampil.php?fokus_mapel=<?php echo $mapel['id_mapel']; ?>" class="badge bg-light text-secondary border text-decoration-none">TP</a>
                                            <a href="penilaian_tampil.php?id_kelas=<?php echo $id_kelas_wali; ?>&id_mapel=<?php echo $mapel['id_mapel']; ?>" class="badge bg-primary text-white text-decoration-none">Input Nilai</a>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                                </div>
                            <?php else: ?>
                                <p class="text-center text-muted py-4 small">Anda tidak mengajar mapel di kelas ini.</p>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- TAB 2: GURU MAPEL (MONITORING) -->
        <div class="tab-pane fade <?php echo (!$is_walas) ? 'show active' : ''; ?>" id="pills-mapel" role="tabpanel">
            <div class="row justify-content-center">
                <div class="col-lg-10">
                    <div class="dash-card">
                        <div class="card-header-modern bg-white sticky-top" style="z-index: 10;">
                            <h5 class="mb-0 fw-bold text-dark">
                                <span class="bg-light text-primary p-2 rounded-circle me-2"><i class="bi bi-bar-chart-steps"></i></span>
                                Pantau Input Nilai Saya
                            </h5>
                        </div>
                        <div class="card-body-modern bg-light bg-opacity-25">
                            
                            <?php if (empty($data_monitoring_nilai)): ?>
                                <div class="text-center py-5">
                                    <img src="assets/img/empty.svg" alt="Empty" style="width: 100px; opacity: 0.5;">
                                    <p class="text-muted mt-3">Belum ada jadwal mengajar.</p>
                                </div>
                            <?php else: ?>
                                <div class="row">
                                <?php foreach ($data_monitoring_nilai as $mon): ?>
                                    <div class="col-md-6 col-xl-4">
                                        <div class="monitoring-item h-100 d-flex flex-column">
                                            <div class="d-flex justify-content-between align-items-start mb-3">
                                                <div>
                                                    <h6 class="fw-bold text-dark mb-0"><?php echo htmlspecialchars($mon['nama_mapel']); ?></h6>
                                                    <small class="text-muted">Kelas <?php echo htmlspecialchars($mon['nama_kelas']); ?></small>
                                                </div>
                                                <span class="badge <?php echo $mon['badge']; ?> rounded-pill"><?php echo $mon['text']; ?></span>
                                            </div>
                                            
                                            <div class="mt-auto">
                                                <div class="d-flex justify-content-between text-muted small mb-1">
                                                    <span>Progres Input</span>
                                                    <span class="fw-bold"><?php echo $mon['persen']; ?>%</span>
                                                </div>
                                                <div class="progress-thin">
                                                    <div class="progress-bar-fill <?php echo $mon['pg_color']; ?>" style="width: <?php echo $mon['persen']; ?>%"></div>
                                                </div>
                                                <div class="mt-1 small text-muted text-end">Siswa Target: <?php echo $mon['jml_siswa_target']; ?></div>
                                                <a href="<?php echo $mon['link_input']; ?>" class="btn btn-outline-primary btn-sm w-100 mt-3 rounded-pill">Buka Penilaian</a>
                                            </div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                                </div>
                            <?php endif; ?>

                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- TAB 3: PANDUAN -->
        <div class="tab-pane fade" id="pills-guide" role="tabpanel">
            <div class="row justify-content-center">
                <div class="col-lg-8">
                    <div class="dash-card">
                        <div class="card-body-modern">
                            <h5 class="fw-bold mb-4 text-center">Alur Kerja Sistem Rapor Digital</h5>
                            
                            <div class="d-flex gap-4 mb-4 align-items-start">
                                <div class="bg-primary text-white rounded-circle d-flex align-items-center justify-content-center flex-shrink-0" style="width: 40px; height: 40px; font-weight: bold;">1</div>
                                <div>
                                    <h6 class="fw-bold">Buat Tujuan Pembelajaran (TP)</h6>
                                    <p class="text-muted small">Guru Mapel wajib membuat TP terlebih dahulu di menu "Data Referensi > Tujuan Pembelajaran". TP ini akan menjadi dasar penilaian.</p>
                                </div>
                            </div>

                            <div class="d-flex gap-4 mb-4 align-items-start">
                                <div class="bg-primary text-white rounded-circle d-flex align-items-center justify-content-center flex-shrink-0" style="width: 40px; height: 40px; font-weight: bold;">2</div>
                                <div>
                                    <h6 class="fw-bold">Input Nilai Sumatif</h6>
                                    <p class="text-muted small">Masuk ke menu "Input Nilai", pilih kelas, lalu buat Rencana Penilaian (Asesmen). Input nilai siswa untuk setiap TP yang dinilai.</p>
                                </div>
                            </div>

                            <?php if ($is_walas): ?>
                            <div class="d-flex gap-4 mb-4 align-items-start">
                                <div class="bg-success text-white rounded-circle d-flex align-items-center justify-content-center flex-shrink-0" style="width: 40px; height: 40px; font-weight: bold;">3</div>
                                <div>
                                    <h6 class="fw-bold">Lengkapi Data Rapor (Wali Kelas)</h6>
                                    <p class="text-muted small">Wali kelas menginput Absensi (Sakit, Izin, Alpha), Catatan Wali Kelas, dan Ekstrakurikuler di menu "Wali Kelas".</p>
                                </div>
                            </div>

                            <div class="d-flex gap-4 align-items-start">
                                <div class="bg-success text-white rounded-circle d-flex align-items-center justify-content-center flex-shrink-0" style="width: 40px; height: 40px; font-weight: bold;">4</div>
                                <div>
                                    <h6 class="fw-bold">Finalisasi & Cetak</h6>
                                    <p class="text-muted small">Setelah semua nilai mapel masuk dan data lengkap, Wali Kelas melakukan "Proses Nilai Akhir" dan mem-finalisasi status rapor agar bisa dicetak/diunduh siswa.</p>
                                </div>
                            </div>
                            <?php endif; ?>

                        </div>
                    </div>
                </div>
            </div>
        </div>

    </div>
</div>

<?php if ($is_walas): ?>
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script src="https://cdn.jsdelivr.net/npm/chartjs-plugin-datalabels@2.0.0"></script>
<script>
document.addEventListener('DOMContentLoaded', function() {
    const ctxWalasRapor = document.getElementById('walasRaporChart');
    if (ctxWalasRapor) {
        Chart.register(ChartDataLabels);
        new Chart(ctxWalasRapor, {
            type: 'doughnut',
            data: {
                datasets: [{
                    data: [<?php echo $progres_rapor_walas; ?>, <?php echo 100 - $progres_rapor_walas; ?>],
                    backgroundColor: ['#0d9488', '#e2e8f0'],
                    borderWidth: 0,
                    cutout: '75%'
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { display: false },
                    tooltip: { enabled: false },
                    datalabels: {
                        display: true,
                        formatter: (value, context) => {
                             return context.dataIndex === 0 ? value + '%' : '';
                        },
                        color: '#134e4a',
                        font: { size: '20', weight: 'bold' },
                        anchor: 'center', align: 'center'
                    }
                }
            }
        });
    }
});
</script>
<?php endif; ?>