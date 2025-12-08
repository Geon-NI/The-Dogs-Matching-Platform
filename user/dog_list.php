<?php
// dog_list.php : 입양 희망자용 유기견 조회 페이지

session_start();
header('Content-Type: text/html; charset=UTF-8');

// 1) 로그인 체크
if (!isset($_SESSION['user_id'])) {
    echo "<script>alert('로그인이 필요합니다.'); location.href='../login/user-login.html';</script>";
    exit;
}

$user_id   = $_SESSION['user_id'];
$user_name = $_SESSION['user_name'] ?? '회원';

// 2) 검색어 및 페이지 파라미터
$keyword = isset($_GET['q']) ? trim($_GET['q']) : '';
// 필터 파라미터(기본값: 전체 = 빈 문자열)
$filter_breed  = isset($_GET['breed'])  ? trim($_GET['breed'])  : '';
$filter_color  = isset($_GET['color'])  ? trim($_GET['color'])  : '';
$filter_gender = isset($_GET['gender']) ? trim($_GET['gender']) : '';

$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
if ($page < 1) $page = 1;

$items_per_page = 8;  // 4개 × 2줄 = 8마리
$offset         = ($page - 1) * $items_per_page;

// 3) DB 접속
$db_username = 'C093299';
$db_password = 'TEST1234';
$db_conn_str = '203.249.87.57/orcl';

$conn = @oci_connect($db_username, $db_password, $db_conn_str, 'AL32UTF8');
if (!$conn) {
    $e   = oci_error();
    $msg = isset($e['message']) ? $e['message'] : 'DB 접속 오류';
    die("DB 접속 실패: " . htmlspecialchars($msg, ENT_QUOTES, 'UTF-8'));
}

// 3-1) 품종 / 색 / 성별 드롭다운용 목록 조회
$breed_list  = [];
$color_list  = [];
$gender_list = [];

// 품종 목록
$sql_breed = "SELECT DISTINCT breed FROM DOG WHERE breed IS NOT NULL ORDER BY breed";
$stid_breed = oci_parse($conn, $sql_breed);
oci_execute($stid_breed);
while ($row = oci_fetch_assoc($stid_breed)) {
    $breed_list[] = $row['BREED'];
}
oci_free_statement($stid_breed);

// 색 목록
$sql_color = "SELECT DISTINCT color FROM DOG WHERE color IS NOT NULL ORDER BY color";
$stid_color = oci_parse($conn, $sql_color);
oci_execute($stid_color);
while ($row = oci_fetch_assoc($stid_color)) {
    $color_list[] = $row['COLOR'];
}
oci_free_statement($stid_color);

// (선택) 성별도 DB 값 기준으로 하고 싶으면
$sql_gender = "SELECT DISTINCT gender FROM DOG WHERE gender IS NOT NULL ORDER BY gender";
$stid_gender = oci_parse($conn, $sql_gender);
oci_execute($stid_gender);
while ($row = oci_fetch_assoc($stid_gender)) {
    $gender_list[] = $row['GENDER'];   // 'M', 'F' 이런 코드들
}
oci_free_statement($stid_gender);



// 4) 전체 레코드 수 조회
$count_sql = "
    SELECT COUNT(*) AS total_count
    FROM DOG d
    JOIN SHELTER s ON d.shelter_id = s.shelter_id
    WHERE 1 = 1
";

if ($keyword !== '') {
    $count_sql .= "
        AND (
            d.name   LIKE :kw
            OR d.breed  LIKE :kw
            OR s.name LIKE :kw
        )
    ";
}

if ($filter_breed !== '') {
    $count_sql .= " AND d.breed = :breed_filter ";
}

if ($filter_color !== '') {
    $count_sql .= " AND d.color = :color_filter ";
}

if ($filter_gender !== '') {
    $count_sql .= " AND d.gender = :gender_filter ";
}

$count_stid = oci_parse($conn, $count_sql);

if ($keyword !== '') {
    $kw = '%' . $keyword . '%';
    oci_bind_by_name($count_stid, ':kw', $kw);
}

if ($filter_breed !== '') {
    oci_bind_by_name($count_stid, ':breed_filter', $filter_breed);
}

if ($filter_color !== '') {
    oci_bind_by_name($count_stid, ':color_filter', $filter_color);
}

if ($filter_gender !== '') {
    oci_bind_by_name($count_stid, ':gender_filter', $filter_gender);
}

oci_execute($count_stid);
$count_row     = oci_fetch_assoc($count_stid);
$total_records = $count_row ? (int)$count_row['TOTAL_COUNT'] : 0;
oci_free_statement($count_stid);

$total_pages = ($total_records > 0) ? (int)ceil($total_records / $items_per_page) : 1;

// 5) 실제 데이터 조회 (페이지네이션 적용)
$data_sql = "
    SELECT *
    FROM (
        SELECT
            d.dog_id,
            d.name,
            d.breed,
            d.age,
            d.gender,
            d.color,
            d.weight,
            d.image_url,
            d.status,
            s.name AS shelter_name,
            ROW_NUMBER() OVER (ORDER BY d.dog_id DESC) AS rnum
        FROM DOG d
        JOIN SHELTER s ON d.shelter_id = s.shelter_id
        WHERE 1 = 1
";

if ($keyword !== '') {
    $data_sql .= "
        AND (
            d.name   LIKE :kw
            OR d.breed  LIKE :kw
            OR s.name LIKE :kw
        )
    ";
}

if ($filter_breed !== '') {
    $data_sql .= " AND d.breed = :breed_filter ";
}

if ($filter_color !== '') {
    $data_sql .= " AND d.color = :color_filter ";
}

if ($filter_gender !== '') {
    $data_sql .= " AND d.gender = :gender_filter ";
}

$data_sql .= "
    )
    WHERE rnum > :offset AND rnum <= :limit
";

$stid = oci_parse($conn, $data_sql);

if ($keyword !== '') {
    oci_bind_by_name($stid, ':kw', $kw);
}

if ($filter_breed !== '') {
    oci_bind_by_name($stid, ':breed_filter', $filter_breed);
}

if ($filter_color !== '') {
    oci_bind_by_name($stid, ':color_filter', $filter_color);
}

if ($filter_gender !== '') {
    oci_bind_by_name($stid, ':gender_filter', $filter_gender);
}

$limit = $offset + $items_per_page;
oci_bind_by_name($stid, ':offset', $offset);
oci_bind_by_name($stid, ':limit',  $limit);

oci_execute($stid);

$dogs = [];
while ($row = oci_fetch_assoc($stid)) {
    $dogs[] = $row;
}

oci_free_statement($stid);
oci_close($conn);
?>
<!DOCTYPE html>
<html lang="ko">
<head>
    <meta charset="UTF-8">
    <title>유기견 조회</title>
    <link rel="stylesheet" href="sidebar.css">
    <link rel="stylesheet" href="dog-list.css">
</head>
<body>
<div class="container">

    <!-- ===== 왼쪽 사이드바 ===== -->
    <aside class="sidebar">
        <div class="profile-section">
            <img src="../img/user.png" alt="사용자 아이콘" class="profile-icon">
            <h2 class="profile-name">
                <?= htmlspecialchars($user_name, ENT_QUOTES, 'UTF-8') ?> 님
            </h2>
            <div class="divider"></div>
        </div>

        <nav class="menu">
            <button class="menu-item" onclick="location.href='user_profile.php'">회원 정보 수정</button>
            <button class="menu-item active" onclick="location.href='dog_list.php'">유기견 조회</button>
            <button class="menu-item" onclick="location.href='shelter_list.php'">보호소 조회</button>
            <button class="menu-item" onclick="location.href='adoption_result.php'">입양 심사 결과</button>
        </nav>

        <button class="logout-btn"
                onclick="if(confirm('로그아웃 하시겠습니까?')) location.href='../login/logout.php';">
            로그아웃
        </button>
    </aside>

    <!-- ===== 오른쪽 메인 콘텐츠 ===== -->
    <main class="main-content">

        <!-- 검색 필터 -->
        <!-- 검색 + 필터 (오른쪽 상단 정렬) -->
<form class="search-filter" method="get" action="dog_list.php">
    <!-- 품종 필터 -->
    <select name="breed" class="filter-select">
        <option value="">전체 품종</option>
        <?php foreach ($breed_list as $b): ?>
            <option value="<?= htmlspecialchars($b, ENT_QUOTES, 'UTF-8') ?>"
                <?= ($filter_breed === $b) ? 'selected' : '' ?>>
                <?= htmlspecialchars($b, ENT_QUOTES, 'UTF-8') ?>
            </option>
        <?php endforeach; ?>
    </select>

    <!-- 색 필터 -->
    <select name="color" class="filter-select">
        <option value="">전체 색</option>
        <?php foreach ($color_list as $c): ?>
            <option value="<?= htmlspecialchars($c, ENT_QUOTES, 'UTF-8') ?>"
                <?= ($filter_color === $c) ? 'selected' : '' ?>
                <?= ($filter_color === $c) ? 'selected' : '' ?>>
                <?= htmlspecialchars($c, ENT_QUOTES, 'UTF-8') ?>
            </option>
        <?php endforeach; ?>
    </select>

    <!-- 성별 필터 (DB 값 사용 버전) -->
    <select name="gender" class="filter-select">
        <option value="">전체 성별</option>
        <?php foreach ($gender_list as $g): ?>
            <option value="<?= htmlspecialchars($g, ENT_QUOTES, 'UTF-8') ?>"
                <?= ($filter_gender === $g) ? 'selected' : '' ?>>
                <?= ($g === 'M') ? '수컷' : (($g === 'F') ? '암컷' : $g) ?>
            </option>
        <?php endforeach; ?>
    </select>
    <button type="submit" class="search-btn">검색</button>
</form>

        <!-- 유기견 카드 그리드 -->
        <section class="dog-grid">
            <?php if (empty($dogs)): ?>
                <p>검색 결과가 없습니다.</p>
            <?php else: ?>
                <?php foreach ($dogs as $dog): ?>
                    <?php
                    $dog_id       = $dog['DOG_ID'];
                    $name         = $dog['NAME'];
                    $breed_name   = $dog['BREED'];
                    $age          = $dog['AGE'];
                    $gender_code  = $dog['GENDER'];
                    $color_name   = $dog['COLOR'];
                    $weight       = $dog['WEIGHT'];
                    $image_url    = $dog['IMAGE_URL'];
                    $shelter_name = $dog['SHELTER_NAME'];

                    // 이미지 기본값
                    if (empty($image_url)) {
                        $image_src = '../img/bichon.png';
                    } else {
                        $image_src = $image_url;
                    }

                    // 성별 텍스트
                    $gender_text = ($gender_code === 'M') ? '수컷' : '암컷';
                    ?>
                    <div class="dog-card" onclick="location.href='dog_detail.php?dog_id=<?= $dog_id ?>'">
                        <div class="dog-image-wrapper">
                            <img src="<?= htmlspecialchars($image_src, ENT_QUOTES, 'UTF-8') ?>"
                                 class="dog-image"
                                 alt="강아지 사진">
                        </div>

                        <div class="dog-name">
                            <?= htmlspecialchars($name, ENT_QUOTES, 'UTF-8') ?>
                        </div>

                        <!-- ✅ 개 종류 · 성별 · O세 -->
                        <div class="dog-info">
                            <?= htmlspecialchars($breed_name, ENT_QUOTES, 'UTF-8') ?> ·
                            <?= htmlspecialchars($gender_text, ENT_QUOTES, 'UTF-8') ?> ·
                            <?= htmlspecialchars($age, ENT_QUOTES, 'UTF-8') ?>세
                        </div>

                        <button type="button"
                                class="detail-btn"
                                onclick="event.stopPropagation(); location.href='dog_detail.php?dog_id=<?= $dog_id ?>';">
                            상세 보기
                        </button>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </section>

        <!-- 페이지네이션 -->
        <div class="pagination">
            <?php
            if ($total_pages > 1) {
                for ($p = 1; $p <= $total_pages; $p++) {
                    $query = http_build_query([
                        'q'    => $keyword,
                        'page' => $p
                    ]);
                    $active_class = ($p === $page) ? 'page-btn active' : 'page-btn';
                    echo "<a class=\"{$active_class}\" href=\"dog_list.php?{$query}\">{$p}</a>";
                }
            }
            ?>
        </div>

    </main>
</div>
</body>
</html>
