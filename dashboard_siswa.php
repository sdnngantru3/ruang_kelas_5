<?php
// dashboard_siswa.php - Konten khusus untuk Siswa (TEAL THEME + BRIGHT ACCENTS)
// UPDATE: Mengubah Header Catatan Pantauan menjadi Orange dan Menambahkan Menu Masuk Kelas

// Pastikan koneksi sudah ada
if (!isset($koneksi)) { die('File ini tidak boleh diakses langsung.'); }

// =================================================================================
// 1. LOGIKA DATA (BACKEND)
// =================================================================================

// Ambil data tahun ajaran aktif
$q_ta_aktif = mysqli_query($koneksi, "SELECT id_tahun_ajaran, tahun_ajaran FROM tahun_ajaran WHERE status = 'Aktif' LIMIT 1");
$data_tahun_aktif = mysqli_fetch_assoc($q_ta_aktif);
$id_tahun_ajaran_aktif = $data_tahun_aktif['id_tahun_ajaran'] ?? 0;
$nama_tahun_ajaran_aktif = $data_tahun_aktif['tahun_ajaran'] ?? 'Tidak Aktif';

// Ambil semester aktif
$q_smt = mysqli_query($koneksi, "SELECT nilai_pengaturan FROM pengaturan WHERE nama_pengaturan = 'semester_aktif' LIMIT 1");
$semester_aktif = mysqli_fetch_assoc($q_smt)['nilai_pengaturan'] ?? 1;
$semester_text = ($semester_aktif == 1) ? 'Ganjil' : 'Genap';

// Ambil data siswa yang login dari session
$id_siswa = $_SESSION['id_siswa'] ?? 0;
$nama_siswa = $_SESSION['nama_siswa'] ?? 'Siswa';

if ($id_siswa == 0) {
    echo "<div class='alert alert-danger'>Error: Data siswa tidak ditemukan. Silakan login kembali.</div>";
    exit;
}

// Ambil detail data siswa
$query_siswa_detail = mysqli_prepare($koneksi,
    "SELECT s.nisn, s.nis, s.foto_siswa, k.nama_kelas, k.fase
     FROM siswa s
     LEFT JOIN kelas k ON s.id_kelas = k.id_kelas
     WHERE s.id_siswa = ?");
mysqli_stmt_bind_param($query_siswa_detail, "i", $id_siswa);
mysqli_stmt_execute($query_siswa_detail);
$siswa_detail = mysqli_fetch_assoc(mysqli_stmt_get_result($query_siswa_detail));
mysqli_stmt_close($query_siswa_detail);

// Tentukan path foto profil siswa
$foto_profil_siswa = 'assets/img/avatar/avatar-1.png';
if (!empty($siswa_detail['foto_siswa'])) {
    $path_to_check = 'uploads/foto_siswa/' . $siswa_detail['foto_siswa'];
    // if (file_exists($path_to_check)) { 
        $foto_profil_siswa = $path_to_check;
    // }
}

// Cek status rapor terakhir siswa
$rapor_final = false;
$data_kehadiran = ['sakit' => 0, 'izin' => 0, 'tanpa_keterangan' => 0];
$catatan_wali = 'Belum ada catatan dari Wali Kelas.';
$id_rapor_siswa = null;

$query_rapor_status = mysqli_prepare($koneksi,
    "SELECT id_rapor, status, sakit, izin, tanpa_keterangan, catatan_wali_kelas
     FROM rapor
     WHERE id_siswa = ? AND semester = ? AND id_tahun_ajaran = ?
     ORDER BY id_rapor DESC LIMIT 1");
mysqli_stmt_bind_param($query_rapor_status, "iii", $id_siswa, $semester_aktif, $id_tahun_ajaran_aktif);
mysqli_stmt_execute($query_rapor_status);
$result_rapor = mysqli_stmt_get_result($query_rapor_status);
if ($rapor_data = mysqli_fetch_assoc($result_rapor)) {
    $rapor_final = ($rapor_data['status'] == 'Final');
    $data_kehadiran['sakit'] = $rapor_data['sakit'] ?? 0;
    $data_kehadiran['izin'] = $rapor_data['izin'] ?? 0;
    $data_kehadiran['tanpa_keterangan'] = $rapor_data['tanpa_keterangan'] ?? 0;
    if(!empty($rapor_data['catatan_wali_kelas'])) {
        $catatan_wali = htmlspecialchars($rapor_data['catatan_wali_kelas']);
    }
    $id_rapor_siswa = $rapor_data['id_rapor'];
}
mysqli_stmt_close($query_rapor_status);

// Ambil 5 nilai sumatif terakhir siswa
$nilai_terakhir = [];
$query_nilai = mysqli_prepare($koneksi,
    "SELECT p.nama_penilaian, p.tanggal_penilaian, mp.nama_mapel, pdn.nilai
     FROM penilaian_detail_nilai pdn
     JOIN penilaian p ON pdn.id_penilaian = p.id_penilaian
     JOIN mata_pelajaran mp ON p.id_mapel = mp.id_mapel
     WHERE pdn.id_siswa = ? AND p.jenis_penilaian = 'Sumatif' AND p.semester = ?
     ORDER BY p.tanggal_penilaian DESC, p.id_penilaian DESC
     LIMIT 5");
mysqli_stmt_bind_param($query_nilai, "ii", $id_siswa, $semester_aktif);
mysqli_stmt_execute($query_nilai);
$result_nilai = mysqli_stmt_get_result($query_nilai);
while ($row = mysqli_fetch_assoc($result_nilai)) {
    $nilai_terakhir[] = $row;
}
mysqli_stmt_close($query_nilai);

// Ambil data ekstrakurikuler
$ekskul_siswa = [];
if ($id_rapor_siswa) {
    $query_ekskul = mysqli_prepare($koneksi,
        "SELECT nama_ekskul, keterangan FROM rapor_detail_ekskul WHERE id_rapor = ? ORDER BY nama_ekskul ASC");
    mysqli_stmt_bind_param($query_ekskul, "i", $id_rapor_siswa);
    mysqli_stmt_execute($query_ekskul);
    $result_ekskul = mysqli_stmt_get_result($query_ekskul);
    while($row = mysqli_fetch_assoc($result_ekskul)){
        $ekskul_siswa[] = $row;
    }
    mysqli_stmt_close($query_ekskul);
}

// [BARU] Ambil Data Catatan Pantauan Guru
$catatan_guru = [];
// Asumsi tabel guru bernama 'guru' dan primary key 'id_guru'. Sesuaikan jika berbeda.
$sql_catatan = "
    SELECT cgw.*, g.nama_guru 
    FROM catatan_guru_wali cgw
    LEFT JOIN guru g ON cgw.id_guru_wali = g.id_guru
    WHERE cgw.id_siswa = ? 
    ORDER BY cgw.tanggal_catatan DESC LIMIT 10"; // Limit 10 agar tidak terlalu panjang

$query_catatan_stmt = mysqli_prepare($koneksi, $sql_catatan);
mysqli_stmt_bind_param($query_catatan_stmt, "i", $id_siswa);
mysqli_stmt_execute($query_catatan_stmt);
$result_catatan = mysqli_stmt_get_result($query_catatan_stmt);
while($row_cat = mysqli_fetch_assoc($result_catatan)){
    $catatan_guru[] = $row_cat;
}
mysqli_stmt_close($query_catatan_stmt);
?>

<!-- =================================================================================
     2. TAMPILAN MODERN (TEAL THEME - MONSTER KEREN AGRESSIVE V4)
================================================================================== -->
<style>
    /* Base Styling */
    .student-dashboard {
        font-family: 'Inter', sans-serif;
        background-color: #f0fdfa; /* Sangat terang, untuk kontras */
        min-height: 100vh;
        padding-bottom: 3rem;
        color: #0f766e; /* Dark Teal Text */
    }

    /* COLORS */
    :root {
        --teal-primary: #0d9488;
        --teal-dark: #065f46;
        --teal-light: #ccfbf1;
        --accent-orange: #f97316; /* Bright Orange for contrast */
        --accent-yellow: #facc15;
    }

    /* 1. HERO PROFILE - DYNAMIC TEAL HEADER */
    .hero-profile-teal {
        background: var(--teal-dark); /* Dark solid background */
        border-radius: 30px;
        padding: 2.5rem;
        color: white;
        position: relative;
        overflow: hidden;
        box-shadow: 0 15px 40px -15px rgba(6, 95, 70, 0.9); /* Shadow Dark Teal Monster */
        margin-bottom: 3rem;
        border-top: 8px solid var(--teal-primary); /* Garis atas cerah */
    }
    /* Efek Keren di Header */
    .hero-profile-teal::before {
        content: ''; position: absolute; top: 0; left: 0; right: 0; bottom: 0;
        background-image: linear-gradient(to right, rgba(13, 148, 136, 0.2) 1px, transparent 1px),
                          linear-gradient(to bottom, rgba(13, 148, 136, 0.2) 1px, transparent 1px);
        background-size: 25px 25px; /* Grid effect */
        opacity: 0.5;
    }
    
    .profile-img-box {
        width: 100px; height: 100px;
        border-radius: 50%;
        border: 5px solid var(--accent-orange); /* Border warna cerah */
        overflow: hidden;
        background: white;
        box-shadow: 0 0 0 2px white; /* Efek cincin putih */
    }
    
    .profile-img-box img { 
        width: 100% !important; 
        height: 100% !important; 
        object-fit: cover; 
        display: block; 
    }
    
    .badge-teal {
        background: var(--teal-primary);
        color: white;
        padding: 6px 16px;
        border-radius: 40px;
        font-size: 0.85rem;
        font-weight: 700;
        box-shadow: 0 2px 5px rgba(0,0,0,0.2);
    }
    .badge-accent {
        background: var(--accent-orange);
        color: white;
        padding: 6px 16px;
        border-radius: 40px;
        font-size: 0.85rem;
        font-weight: 700;
        box-shadow: 0 2px 5px rgba(0,0,0,0.2);
    }
    /* Tambahan Styling Badge Link Ruang Kelas */
    .badge-ruang-kelas {
        background: var(--accent-yellow);
        color: #1e293b;
        padding: 6px 16px;
        border-radius: 40px;
        font-size: 0.85rem;
        font-weight: 800;
        text-decoration: none;
        box-shadow: 0 4px 10px rgba(250, 204, 21, 0.4);
        transition: all 0.3s ease;
        display: inline-flex;
        align-items: center;
        border: 2px solid transparent;
    }
    .badge-ruang-kelas:hover {
        background: #fef08a;
        color: #0f172a;
        transform: translateY(-2px);
        box-shadow: 0 6px 15px rgba(250, 204, 21, 0.6);
        border: 2px solid white;
    }

    /* 2. MODERN CARDS */
    .modern-card {
        background: white;
        border-radius: 20px;
        border: 1px solid var(--teal-light);
        box-shadow: 0 10px 30px -10px rgba(0, 0, 0, 0.15); 
        height: 100%;
        transition: transform 0.4s ease-out, box-shadow 0.4s ease;
        overflow: hidden;
    }
    .modern-card:hover { 
        transform: translateY(-5px); 
        box-shadow: 0 20px 45px -10px rgba(13, 148, 136, 0.3);
    }
    .card-header-dynamic {
        background: var(--teal-primary);
        padding: 1rem 1.5rem;
        font-weight: 900;
        font-size: 1.1rem;
        display: flex;
        justify-content: space-between;
        align-items: center;
        color: white;
        border-bottom: 3px solid var(--teal-dark);
    }
    .card-body-clean { padding: 1.5rem; }

    /* 3. RAPOR STATUS CARD */
    .card-rapor-ready {
        background: linear-gradient(45deg, var(--teal-primary) 0%, #14b8a6 100%);
        box-shadow: 0 10px 20px rgba(13, 148, 136, 0.5);
        color: white; border: none; border-radius: 20px; padding: 1.5rem;
    }
    .card-rapor-pending {
        background: linear-gradient(45deg, var(--accent-orange) 0%, #facc15 100%); /* Orange to Yellow */
        box-shadow: 0 10px 20px rgba(249, 115, 22, 0.5);
        color: white; border: none; border-radius: 20px; padding: 1.5rem;
    }
    .rapor-icon-box {
        font-size: 3rem; margin-bottom: 1rem;
        text-shadow: 0 3px 5px rgba(0,0,0,0.2);
    }
    .btn-rapor-action {
        background: white; color: var(--teal-dark); font-weight: 800;
        border: none; padding: 10px 25px; border-radius: 50px;
        transition: all 0.3s;
        text-transform: uppercase;
        box-shadow: 0 5px 10px rgba(0,0,0,0.2);
    }
    .btn-rapor-action:hover { transform: scale(1.05); }

    /* 4. ATTENDANCE TILE */
    .att-tile {
        background: var(--teal-light);
        padding: 1rem;
        border-radius: 12px;
        box-shadow: 0 2px 5px rgba(0,0,0,0.05);
        display: flex; flex-direction: column; align-items: center;
    }
    .att-val { font-size: 1.8rem; font-weight: 900; line-height: 1; margin-bottom: 5px; }
    .att-lbl { font-size: 0.75rem; text-transform: uppercase; font-weight: 700; color: #4b5563; }
    
    .att-sakit { background: #fef2f2; border: 1px solid #fca5a5; color: #b91c1c; }
    .att-izin { background: #fff7ed; border: 1px solid #fed7aa; color: #c2410c; }
    .att-alpha { background: #f0fdfa; border: 1px solid #99f6e4; color: var(--teal-dark); }
    .att-total-box {
        background: var(--teal-dark); color: white; border-radius: 12px; padding: 1rem; text-align: center;
    }

    /* 5. NILAI LIST */
    .grade-item {
        display: grid; 
        grid-template-columns: 40px 1fr auto; /* Ikon | Detail | Score */
        gap: 10px; align-items: center;
        padding: 0.75rem 1rem; margin-bottom: 0.5rem;
        background: #f7fdfd; border-radius: 10px;
        border-left: 4px solid var(--teal-primary);
        transition: background 0.2s;
    }
    .grade-item:hover { background: var(--teal-light); }
    .grade-icon {
        width: 40px; height: 40px; border-radius: 8px;
        background: var(--teal-primary); color: white;
        display: flex; align-items: center; justify-content: center; font-size: 1.2rem;
    }
    .grade-detail h6 { font-size: 0.95rem; font-weight: 700; color: var(--teal-dark); margin-bottom: 0; }
    .grade-detail small { font-size: 0.7rem; color: #6b7280; }
    .grade-score-box {
        font-size: 1.1rem; font-weight: 900; color: white;
        background: var(--accent-orange);
        padding: 4px 10px; border-radius: 8px;
        min-width: 50px; text-align: center;
    }

    /* 6. EXTRAS & CATATAN GURU STYLES */
    .note-box-shadow {
        background: #fefcbf; border-left: 6px solid #fbbf24; 
        padding: 1.5rem; border-radius: 0 15px 15px 0;
        font-style: italic; color: #4b5563; position: relative;
        box-shadow: 0 5px 10px rgba(0,0,0,0.05);
    }
    .ekskul-detail {
        background: var(--teal-light); padding: 1rem;
        border-radius: 12px; border: 1px solid #99f6e4;
        box-shadow: 0 2px 5px rgba(0,0,0,0.05);
    }
    .ekskul-badge-teal {
        display: inline-block; padding: 8px 16px;
        background: var(--teal-dark); color: white; 
        border-radius: 20px; font-weight: 700; font-size: 0.8rem;
    }

    /* New: Table Catatan Pantauan Styling */
    .table-custom-teal {
        width: 100%; border-collapse: separate; border-spacing: 0 10px;
    }
    .table-custom-teal thead th {
        border: none; color: #6b7280; font-size: 0.8rem; text-transform: uppercase; letter-spacing: 1px;
        padding: 0 1rem;
    }
    .table-custom-teal tbody tr {
        background: #f8fafc; box-shadow: 0 2px 5px rgba(0,0,0,0.03);
        transition: transform 0.2s;
    }
    .table-custom-teal tbody tr:hover { transform: scale(1.01); background: white; }
    .table-custom-teal td {
        padding: 1.2rem 1rem; vertical-align: middle; border: none;
    }
    .table-custom-teal td:first-child { border-top-left-radius: 10px; border-bottom-left-radius: 10px; }
    .table-custom-teal td:last-child { border-top-right-radius: 10px; border-bottom-right-radius: 10px; }
    
    .badge-kategori {
        padding: 5px 12px; border-radius: 6px; font-size: 0.75rem; font-weight: 700; text-transform: uppercase;
        letter-spacing: 0.5px;
    }
    .bk-default { background: #e2e8f0; color: #475569; }
    .bk-kedisiplinan { background: #fee2e2; color: #b91c1c; } /* Merah */
    .bk-prestasi { background: #dcfce7; color: #15803d; } /* Hijau */
    .bk-kerajinan { background: #e0f2fe; color: #0369a1; } /* Biru */
    .bk-kerapian { background: #f3e8ff; color: #7e22ce; } /* Ungu */

    /* Responsiveness */
    @media (max-width: 992px) {
        .hero-profile-teal { padding: 2rem 1rem; }
        .hero-profile-teal .d-flex.align-items-center { flex-direction: column; text-align: center; }
        .profile-img-box { margin-bottom: 1rem; margin-right: 0 !important; }
        .hero-profile-teal .text-md-end { text-align: center !important; }
        .attendance-grid { grid-template-columns: repeat(3, 1fr); }
    }
    @media (min-width: 992px) {
        .content-main-grid {
            display: grid;
            grid-template-columns: 2fr 1fr; /* Kolom nilai lebih lebar */
            gap: 20px;
        }
    }
</style>

<div class="student-dashboard container-fluid px-4 pt-4">
    
    <!-- 1. HERO PROFILE (HEADER DINAMIS) -->
    <div class="hero-profile-teal">
        <div class="row align-items-center position-relative" style="z-index: 2;">
            <div class="col-lg-8 col-md-7 d-flex align-items-center">
                <div class="profile-img-box me-4 flex-shrink-0">
                    <img src="<?php echo htmlspecialchars($foto_profil_siswa); ?>" 
                         onerror="this.onerror=null;this.src='https://placehold.co/100x100/065f46/ccfbf1?text=<?php echo substr($nama_siswa, 0, 1); ?>';" 
                         alt="Profil">
                </div>
                <div>
                    <h2 class="mb-1 fw-bolder fs-1">HALO, <?php echo strtoupper(htmlspecialchars($nama_siswa)); ?>!</h2>
                    <p class="mb-3 fs-6 fw-medium opacity-90">Selamat datang di <strong>LAPORAN HASIL BELAJAR</strong> Anda.</p>
                    <div class="d-flex flex-wrap gap-3 mt-3 justify-content-center justify-content-md-start align-items-center">
                        <span class="badge-teal"><i class="bi bi-mortarboard-fill me-2"></i>KELAS <?php echo htmlspecialchars($siswa_detail['nama_kelas']); ?></span>
                        <span class="badge-accent"><i class="bi bi-upc-scan me-2"></i>NISN: <?php echo htmlspecialchars($siswa_detail['nisn']); ?></span>
                        <!-- TOMBOL TAMBAHAN: MASUK KELAS -->
                        <a href="https://sdnngantru3.github.io/ruang_kelas_5" class="badge-ruang-kelas">
                            <i class="bi bi-box-arrow-in-right me-2"></i>MASUK KELAS
                        </a>
                    </div>
                </div>
            </div>
            <div class="col-lg-4 col-md-5 text-md-end mt-4 mt-md-0">
                <div class="p-3 rounded-4" style="background: rgba(255,255,255,0.15); border: 1px solid rgba(255,255,255,0.3);">
                    <small class="text-uppercase d-block mb-1 opacity-90 fw-bold letter-spacing-1">Periode Aktif</small>
                    <h5 class="mb-0 fw-bolder fs-4"><?php echo $nama_tahun_ajaran_aktif; ?></h5>
                    <span class="badge bg-white text-teal mt-2 fw-bolder" style="color: var(--teal-dark);">SEMESTER <?php echo strtoupper($semester_text); ?></span>
                </div>
            </div>
        </div>
    </div>

    <!-- 2. STATUS ROW (Rapor & Kehadiran) -->
    <div class="row g-4 mb-5">
        
        <!-- Status Rapor -->
        <div class="col-lg-4">
            <div class="modern-card <?php echo $rapor_final ? 'card-rapor-ready' : 'card-rapor-pending'; ?>">
                <div class="d-flex flex-column align-items-center justify-content-center text-center h-100 py-4">
                    <?php if($rapor_final): ?>
                        <i class="bi bi-file-earmark-check-fill rapor-icon-box"></i>
                        <h3 class="fw-bolder fs-4 mb-2">RAPOR FINAL SIAP!</h3>
                        <p class="mb-4 fs-6 opacity-90 px-3">Lihat pencapaian semester ini.</p>
                        <a href="rapor_pdf.php?id_siswa=<?php echo $id_siswa; ?>" target="_blank" class="btn btn-rapor-action">
                            <i class="bi bi-cloud-arrow-down-fill me-2"></i>AMBIL RAPOR
                        </a>
                    <?php else: ?>
                        <i class="bi bi-hourglass-split rapor-icon-box"></i>
                        <h3 class="fw-bolder fs-4 mb-2">PENGOLAHAN DATA</h3>
                        <p class="mb-0 fs-6 opacity-90 px-3">Data nilai sedang direkap oleh Guru.</p>
                        <small class="mt-2 d-block opacity-75 fw-bold">Silakan cek kembali secara berkala.</small>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Statistik Kehadiran -->
        <div class="col-lg-8">
            <div class="modern-card">
                <div class="card-header-dynamic">
                    <span><i class="bi bi-calendar-check-fill me-2"></i>STATISTIK KEHADIRAN</span>
                    <span class="badge bg-white text-teal-dark fw-bolder border border-teal-200 p-2" style="color: var(--teal-dark);">SEMESTER INI</span>
                </div>
                <div class="card-body-clean">
                    <div class="row g-4">
                        <?php 
                        $kehadiran_labels = ['sakit' => 'SAKIT', 'izin' => 'IZIN', 'tanpa_keterangan' => 'ALPHA'];
                        $kehadiran_classes = ['sakit' => 'att-sakit', 'izin' => 'att-izin', 'tanpa_keterangan' => 'att-alpha'];
                        $total_absen = array_sum($data_kehadiran);
                        
                        foreach ($kehadiran_labels as $key => $label): ?>
                            <div class="col-4">
                                <div class="att-tile <?php echo $kehadiran_classes[$key]; ?>">
                                    <div class="att-val"><?php echo $data_kehadiran[$key]; ?></div>
                                    <div class="att-lbl"><?php echo $label; ?></div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                        
                        <div class="col-12 mt-4">
                             <div class="att-total-box">
                                <?php if ($total_absen == 0): ?>
                                    <h5 class="mb-0 fw-bolder fs-5"><i class="bi bi-award-fill me-2 text-warning"></i> KEHADIRAN SEMPURNA!</h5>
                                    <small class="d-block mt-1 opacity-90">Terus pertahaman konsistensi luar biasa ini.</small>
                                <?php else: ?>
                                    <h5 class="mb-0 fw-bolder fs-5">TOTAL KETIDAKHADIRAN: <span class="text-warning"><?php echo $total_absen; ?> HARI</span></h5>
                                    <small class="d-block mt-1 opacity-90">Tingkatkan kehadiran di periode selanjutnya.</small>
                                <?php endif; ?>
                             </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- 3. CONTENT ROW (Nilai & Extras) -->
    <div class="content-main-grid mb-5">
        
        <!-- Kolom Kiri: Nilai Terbaru -->
        <div class="nilai-section">
            <div class="modern-card">
                <div class="card-header-dynamic">
                    <span><i class="bi bi-bar-chart-line-fill me-2"></i>NILAI SUMATIF TERAKHIR</span>
                    <a href="siswa_lihat_nilai.php" class="btn btn-sm btn-light fw-bold rounded-pill" style="color: var(--teal-dark);">LIHAT SEMUA <i class="bi bi-arrow-right-circle-fill ms-1"></i></a>
                </div>
                <div class="card-body-clean bg-light bg-opacity-25">
                    <?php if (!empty($nilai_terakhir)): ?>
                        <ul class="list-unstyled">
                            <?php foreach ($nilai_terakhir as $nilai): ?>
                                <li class="grade-item">
                                    <div class="grade-icon flex-shrink-0">
                                        <i class="bi bi-journal-bookmark-fill"></i>
                                    </div>
                                    <div class="grade-detail flex-grow-1">
                                        <h6 class="text-truncate" style="max-width: 95%;"><?php echo htmlspecialchars($nilai['nama_mapel']); ?></h6>
                                        <small class="d-block text-truncate">
                                            <i class="bi bi-tag-fill me-1"></i> <?php echo htmlspecialchars($nilai['nama_penilaian']); ?>
                                            &bull; <?php echo date('d M Y', strtotime($nilai['tanggal_penilaian'])); ?>
                                        </small>
                                    </div>
                                    <div class="grade-score-box flex-shrink-0">
                                        <?php echo $nilai['nilai']; ?>
                                    </div>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php else: ?>
                        <div class="text-center py-5">
                            <div class="mb-3 opacity-30" style="font-size: 5rem; color: var(--teal-primary);"><i class="bi bi-inbox"></i></div>
                            <h6 class="fw-bold fs-4">DATA NILAI KOSONG</h6>
                            <p class="text-muted small mb-0">Guru Mata Pelajaran belum menginput nilai sumatif.</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Kolom Kanan: Catatan & Ekskul -->
        <div class="extras-section">
            <div class="d-flex flex-column gap-4">
                
                <!-- Catatan Wali Kelas (Rapor) -->
                <div class="modern-card">
                    <div class="card-header-dynamic" style="background: var(--accent-orange);">
                        <span><i class="bi bi-chat-quote-fill me-2"></i>PESAN WALI KELAS (RAPOR)</span>
                    </div>
                    <div class="card-body-clean">
                        <div class="note-box-shadow">
                            <i class="bi bi-quote position-absolute top-0 start-0 m-2 fs-1" style="color: #fbbf24; opacity: 0.3;"></i>
                            <p class="mb-0 lh-lg fw-medium pt-3 fs-6">
                                <?php echo nl2br($catatan_wali); ?>
                            </p>
                        </div>
                    </div>
                </div>

                <!-- Ekstrakurikuler -->
                <div class="modern-card">
                    <div class="card-header-dynamic">
                        <span><i class="bi bi-trophy-fill me-2"></i>EKSTRAKURIKULER</span>
                    </div>
                    <div class="card-body-clean">
                        <?php if (!empty($ekskul_siswa)): ?>
                            <div class="d-flex flex-column gap-3">
                                <?php foreach ($ekskul_siswa as $ekskul): ?>
                                    <div class="ekskul-detail">
                                        <span class="ekskul-badge-teal shadow-sm">
                                            <?php echo htmlspecialchars($ekskul['nama_ekskul']); ?>
                                        </span>
                                        <small class="text-muted ps-1 d-block mt-2 fw-medium">
                                            <i class="bi bi-info-circle me-1" style="color: var(--teal-primary);"></i> 
                                            <?php echo htmlspecialchars($ekskul['keterangan']); ?>
                                        </small>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php else: ?>
                            <div class="text-center py-4">
                                <small class="text-muted fst-italic fs-6">Tidak mengikuti ekstrakurikuler semester ini.</small>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

            </div>
        </div>

    </div>

    <!-- 4. NEW ROW: CATATAN PANTAUAN GURU (TABEL BARU) -->
    <div class="row">
        <div class="col-12">
            <div class="modern-card">
                <div class="card-header-dynamic" style="background: var(--accent-orange);"> <!-- Update: Warna Orange -->
                    <span><i class="bi bi-eye-fill me-2"></i>CATATAN PANTAUAN GURU WALI</span>
                    <span class="badge bg-white fw-bolder p-2" style="color: var(--accent-orange);">RIWAYAT AKTIVITAS</span>
                </div>
                <div class="card-body-clean">
                    <?php if (!empty($catatan_guru)): ?>
                        <div class="table-responsive">
                            <table class="table-custom-teal">
                                <thead>
                                    <tr>
                                        <th width="15%">TANGGAL</th>
                                        <th width="15%">KATEGORI</th>
                                        <th width="50%">ISI CATATAN</th>
                                        <th width="20%">GURU WALI</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($catatan_guru as $cat): 
                                        // Logika warna badge kategori
                                        $kategori_lower = strtolower($cat['kategori_catatan']);
                                        $badge_class = 'bk-default';
                                        if (strpos($kategori_lower, 'disiplin') !== false || strpos($kategori_lower, 'pelanggaran') !== false) {
                                            $badge_class = 'bk-kedisiplinan';
                                        } elseif (strpos($kategori_lower, 'prestasi') !== false) {
                                            $badge_class = 'bk-prestasi';
                                        } elseif (strpos($kategori_lower, 'rajin') !== false) {
                                            $badge_class = 'bk-kerajinan';
                                        } elseif (strpos($kategori_lower, 'rapi') !== false) {
                                            $badge_class = 'bk-kerapian';
                                        }
                                    ?>
                                    <tr>
                                        <td>
                                            <div class="fw-bold text-dark"><?php echo date('d M Y', strtotime($cat['tanggal_catatan'])); ?></div>
                                            <small class="text-muted"><?php echo date('H:i', strtotime($cat['tanggal_catatan'])); ?> WIB</small>
                                        </td>
                                        <td>
                                            <span class="badge-kategori <?php echo $badge_class; ?>">
                                                <?php echo htmlspecialchars($cat['kategori_catatan']); ?>
                                            </span>
                                        </td>
                                        <td>
                                            <span class="d-block text-secondary fw-medium" style="line-height: 1.6;">
                                                <?php echo nl2br(htmlspecialchars($cat['isi_catatan'])); ?>
                                            </span>
                                        </td>
                                        <td>
                                            <div class="d-flex align-items-center">
                                                <div class="bg-light rounded-circle p-2 me-2 text-secondary">
                                                    <i class="bi bi-person-badge"></i>
                                                </div>
                                                <div>
                                                    <span class="d-block fw-bold text-dark" style="font-size: 0.9rem;">
                                                        <?php echo !empty($cat['nama_guru']) ? htmlspecialchars($cat['nama_guru']) : 'Administrator'; ?>
                                                    </span>
                                                    <small class="text-muted" style="font-size: 0.75rem;">Guru/Wali</small>
                                                </div>
                                            </div>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php else: ?>
                        <div class="text-center py-5 bg-light rounded-3 border border-light">
                            <i class="bi bi-clipboard-check text-secondary mb-3" style="font-size: 3rem; opacity: 0.5;"></i>
                            <h6 class="fw-bold text-secondary">BELUM ADA CATATAN PANTAUAN</h6>
                            <p class="mb-0 small text-muted">Belum ada catatan aktivitas atau pantauan khusus dari Guru untuk saat ini.</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

</div>