<?php

session_start();

// 1. 로그인 체크
if (!isset($_SESSION['shelter_id'])) {
    echo "<script>alert('로그인이 필요합니다.'); location.href='../login/shelter-login.html';</script>";
    exit;
}

$shelter_id = $_SESSION['shelter_id'];

// 2. Oracle DB 접속 정보 (★ 비밀번호는 민기 계정에 맞게 수정)
$db_username = 'C093299';             // sqlplus 아이디
$db_password = 'TEST1234';   // sqlplus 비밀번호
$db_conn_str = '203.249.87.57/orcl';  // 호스트/서비스명

$conn = @oci_connect($db_username, $db_password, $db_conn_str, 'AL32UTF8');

if (!$conn) {
    $e = oci_error();
    $msg = isset($e['message']) ? $e['message'] : 'DB 접속 오류';
    echo "<script>alert('DB 접속에 실패했습니다.\\n{$msg}'); history.back();</script>";
    exit;
}

// 3. SHELTER + REGION 조인해서 정보 가져오기
//    DDL 기준 컬럼 구조 참고: SHELTER, REGION
$sql = "
    SELECT 
        s.name       AS shelter_name,
        s.phone      AS shelter_phone,
        s.open_time  AS open_time,
        s.close_time AS close_time,
        s.detail     AS detail,
        r.city       AS city,
        r.district   AS district
    FROM SHELTER s
    JOIN REGION r
      ON s.region_id = r.region_id
    WHERE s.shelter_id = :shelter_id
";

$stmt = oci_parse($conn, $sql);
oci_bind_by_name($stmt, ':shelter_id', $shelter_id);

if (!oci_execute($stmt)) {
    $e = oci_error($stmt);
    oci_free_statement($stmt);
    oci_close($conn);
    echo "<script>alert('보호소 정보를 불러오는 중 오류가 발생했습니다.'); history.back();</script>";
    exit;
}

$row = oci_fetch_assoc($stmt);

if (!$row) {
    // 혹시라도 삭제된 계정으로 로그인한 경우
    oci_free_statement($stmt);
    oci_close($conn);
    echo "<script>alert('보호소 정보를 찾을 수 없습니다. 다시 로그인해주세요.'); location.href='../login/shelter-login.html';</script>";
    exit;
}

// PHP 변수에 담기
$shelter_name  = $row['SHELTER_NAME'];
$shelter_phone = $row['SHELTER_PHONE'];
$open_time     = $row['OPEN_TIME'];
$close_time    = $row['CLOSE_TIME'];
$city          = $row['CITY'];
$district      = $row['DISTRICT'];
$detail        = $row['DETAIL'];

// 주소 한 줄로 합치기
$full_address = $city . ' ' . $district . "<br>" . $detail;

oci_free_statement($stmt);
oci_close($conn);
?>
<!DOCTYPE html>
<html lang="ko">

<head>
    <meta charset="UTF-8">
    <title>보호소 정보</title>
    <link rel="stylesheet" href="sidebar.css">
    <link rel="stylesheet" href="shelter-info.css">
</head>

<body>

<div class="page-container">

    <!-- 왼쪽 사이드바 -->
    <aside class="sidebar">
        <div class="sidebar-logo-box">
            <!-- 보호소 로고 (클릭 시 이 페이지로 이동) -->
            <a href="shelter_info.php">
                <img src="../img/shelter.png" class="sidebar-logo" alt="로고">
            </a>
        </div>

        <!-- ★ DB에서 가져온 이름 -->
        <div class="sidebar-shelter-name" id="sidebarShelterName">
            <?php echo htmlspecialchars($shelter_name); ?>
        </div>

        <nav class="sidebar-menu">
            <button class="menu-btn" onclick="location.href='shelter_edit.php'">회원정보 수정</button>
            <button class="menu-btn" onclick="location.href='dog_list.php'">유기견 관리</button>
            <button class="menu-btn" onclick="location.href='notice_list.php'">공고 관리</button>
        </nav>

        <!-- 로그아웃 (원하면 나중에 logout.php 연결) -->
        <form class="logout-btn" action="../login/logout.php" method="post">
            <button type="submit" class="logout-font">로그아웃</button>
        </form>
    </aside>

    <!-- 오른쪽 메인 영역 -->
    <main class="main-area">

        <section class="info-section">
            <!-- 전화번호 -->
            <div class="info-box">
                <div class="icon-wrapper circle-icon">
                    <i data-lucide="phone-call" class="info-icon"></i>
                </div>
                <div class="info-text" id="shelterPhone">
                    <?php echo htmlspecialchars($shelter_phone); ?>
                </div>
            </div>

            <!-- 주소 -->
            <div class="info-box info-box-center">
                <div class="icon-wrapper location-wrapper">
                    <i data-lucide="map-pin" class="info-icon"></i>
                    <div class="location-shadow"></div>
                </div>
                <div class="info-text address-text" id="shelterAddress">
                    <?php echo $full_address; // <br> 포함이라 htmlspecialchars 안 씀 ?>
                </div>
            </div>

            <!-- 영업시간 -->
            <div class="info-box">
                <div class="icon-wrapper circle-icon">
                    <i data-lucide="clock" class="info-icon"></i>
                </div>
                <div class="info-text" id="shelterTime">
                    <?php echo htmlspecialchars($open_time . ' ~ ' . $close_time); ?>
                </div>
            </div>
        </section>

    </main>
</div>

<script src="https://unpkg.com/lucide@latest"></script>
<script src="shelter-info.js"></script>
</body>
</html>
