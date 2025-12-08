<?php
// notice_detail.php : 특정 강아지 입양 신청자 심사 페이지

session_start();
header('Content-Type: text/html; charset=UTF-8');

// 1) 로그인 체크
if (!isset($_SESSION['shelter_id'])) {
    echo "<script>alert('로그인 후 이용해주세요.'); location.href='../login/shelter_login.html';</script>";
    exit;
}

$shelter_id   = $_SESSION['shelter_id'];
$shelter_name = $_SESSION['shelter_name'] ?? 'OOO 보호소';

// 2) DB 접속
$db_username = 'C093299';     // 계정 맞게 수정
$db_password = 'TEST1234';
$db_conn_str = '203.249.87.57/orcl';

$conn = @oci_connect($db_username, $db_password, $db_conn_str, 'AL32UTF8');
if (!$conn) {
    $e = oci_error();
    die('DB 접속 실패: ' . htmlspecialchars($e['message']));
}

// 공통 dog_id
$dog_id = 0;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $dog_id = isset($_POST['dog_id']) ? (int)$_POST['dog_id'] : 0;
} else {
    $dog_id = isset($_GET['dog_id']) ? (int)$_GET['dog_id'] : 0;
}

if ($dog_id <= 0) {
    echo "<script>alert('잘못된 접근입니다. (dog_id 누락)'); location.href='notice_list.php';</script>";
    oci_close($conn);
    exit;
}

/* ============================================================
   3) 강아지 정보 + 권한 체크
   ============================================================ */
$sql_dog = "
    SELECT
        d.shelter_id,
        d.name,
        d.breed,
        d.age,
        d.gender,
        d.color,
        d.weight,
        d.image_url,
        d.status
    FROM DOG d
    WHERE d.dog_id = :dog_id
";
$stmt_d = oci_parse($conn, $sql_dog);
oci_bind_by_name($stmt_d, ':dog_id', $dog_id);
oci_execute($stmt_d);
$dog = oci_fetch_assoc($stmt_d);
oci_free_statement($stmt_d);

if (!$dog) {
    echo "<script>alert('해당 유기견 정보를 찾을 수 없습니다.'); location.href='notice_list.php';</script>";
    oci_close($conn);
    exit;
}

if ($dog['SHELTER_ID'] !== $shelter_id) {
    echo "<script>alert('해당 유기견에 대한 권한이 없습니다.'); location.href='notice_list.php';</script>";
    oci_close($conn);
    exit;
}

$dog_status = $dog['STATUS'];  // IN_CARE / AVAILABLE / ADOPTED

// 보호 중(IN_CARE) 상태면 바로 경고 후 돌려보내기
if ($_SERVER['REQUEST_METHOD'] === 'GET' && $dog_status === 'IN_CARE') {
    echo "<script>alert('공고 등록을 해주세요.'); location.href='notice_list.php';</script>";
    oci_close($conn);
    exit;
}

/* ============================================================
   4) POST : 최종 입양자 확정 처리 (AVAILABLE 상태에서만)
   ============================================================ */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    if ($dog_status !== 'AVAILABLE') {
        echo "<script>alert('공고 등록 상태에서만 최종 입양자를 선택할 수 있습니다.'); history.back();</script>";
        oci_close($conn);
        exit;
    }

    $selected_request_id = isset($_POST['selected_request']) ? (int)$_POST['selected_request'] : 0;
    if ($selected_request_id <= 0) {
        echo "<script>alert('최종 입양자를 선택해주세요.'); history.back();</script>";
        oci_close($conn);
        exit;
    }

    try {
        // 1) 선택된 신청 = APPROVED
        $sql_approve = "
            UPDATE ADOPTION_REQUEST
            SET status = 'APPROVED'
            WHERE request_id = :rid
              AND dog_id = :dog_id
        ";
        $stmt_a = oci_parse($conn, $sql_approve);
        oci_bind_by_name($stmt_a, ':rid', $selected_request_id);
        oci_bind_by_name($stmt_a, ':dog_id', $dog_id);
        if (!oci_execute($stmt_a, OCI_NO_AUTO_COMMIT)) {
            $e = oci_error($stmt_a);
            throw new Exception('입양 승인 처리 실패: ' . $e['message']);
        }
        oci_free_statement($stmt_a);

        // 2) 나머지는 REJECTED
        $sql_reject = "
            UPDATE ADOPTION_REQUEST
            SET status = 'REJECTED'
            WHERE dog_id = :dog_id
              AND request_id <> :rid
        ";
        $stmt_r = oci_parse($conn, $sql_reject);
        oci_bind_by_name($stmt_r, ':dog_id', $dog_id);
        oci_bind_by_name($stmt_r, ':rid', $selected_request_id);
        if (!oci_execute($stmt_r, OCI_NO_AUTO_COMMIT)) {
            $e = oci_error($stmt_r);
            throw new Exception('입양 거절 처리 실패: ' . $e['message']);
        }
        oci_free_statement($stmt_r);

        // 3) DOG 상태를 ADOPTED로 변경
        $sql_dog_up = "
            UPDATE DOG
            SET status = 'ADOPTED'
            WHERE dog_id = :dog_id
        ";
        $stmt_du = oci_parse($conn, $sql_dog_up);
        oci_bind_by_name($stmt_du, ':dog_id', $dog_id);
        if (!oci_execute($stmt_du, OCI_NO_AUTO_COMMIT)) {
            $e = oci_error($stmt_du);
            throw new Exception('강아지 상태 변경 실패: ' . $e['message']);
        }
        oci_free_statement($stmt_du);

        oci_commit($conn);
        oci_close($conn);

        echo "<script>alert('최종 입양자가 확정되었습니다.'); location.href='dog_list.php';</script>";
        exit;

    } catch (Exception $ex) {
        oci_rollback($conn);
        $msg = htmlspecialchars($ex->getMessage());
        echo "<script>alert('저장 중 오류가 발생했습니다.\\n{$msg}'); history.back();</script>";
        oci_close($conn);
        exit;
    }
}

/* ============================================================
   5) GET : 페이지 표시용 데이터 조회
   ============================================================ */

// 강아지 정보 표시용
$dog_name   = $dog['NAME'];
$dog_breed  = $dog['BREED'];
$dog_age    = $dog['AGE'];
$dog_gender = $dog['GENDER'] === 'M' ? '수컷' : '암컷';
$dog_color  = $dog['COLOR'];
$dog_weight = $dog['WEIGHT'];
$dog_img    = $dog['IMAGE_URL'] ?: '../img/dog-placeholder.png';

// 신청자 목록
$sql_req = "
    SELECT
        ar.request_id,
        ar.user_id,
        TO_CHAR(ar.datetime, 'YYYY.MM.DD HH24:MI') AS req_time,
        TRUNC(MONTHS_BETWEEN(SYSDATE, u.birthdate) / 12) AS age,
        ar.status
    FROM ADOPTION_REQUEST ar
         JOIN USERS u ON u.user_id = ar.user_id
    WHERE ar.dog_id = :dog_id
    ORDER BY ar.datetime DESC
";
$stmt_req = oci_parse($conn, $sql_req);
oci_bind_by_name($stmt_req, ':dog_id', $dog_id);
oci_execute($stmt_req);

$applicants = [];
while ($row = oci_fetch_assoc($stmt_req)) {
    $applicants[] = $row;
}
oci_free_statement($stmt_req);

$read_only = ($dog_status === 'ADOPTED'); // 입양 완료 상태면 수정 불가

oci_close($conn);
?>
<!DOCTYPE html>
<html lang="ko">

<head>
    <meta charset="UTF-8">
    <title>공고 신청자 관리</title>
    <link rel="stylesheet" href="sidebar.css">
    <link rel="stylesheet" href="notice-detail.css">
</head>

<body>

<div class="page-container">

    <!-- 왼쪽 사이드바 -->
    <aside class="sidebar">
        <div class="sidebar-logo-box">
            <a href="shelter_info.php">
                <img src="../img/shelter.png" class="sidebar-logo" alt="로고">
            </a>
        </div>

        <div class="sidebar-shelter-name" id="sidebarShelterName">
            <?php echo htmlspecialchars($shelter_name); ?>
        </div>

        <nav class="sidebar-menu">
            <button class="menu-btn" onclick="location.href='shelter_edit.php'">회원정보 수정</button>
            <button class="menu-btn" onclick="location.href='dog_list.php'">유기견 관리</button>
            <button class="menu-btn active" onclick="location.href='notice_list.php'">공고 관리</button>
        </nav>

        <form class="logout-btn" action="../login/logout.php" method="post">
            <button type="submit" class="logout-font">로그아웃</button>
        </form>
    </aside>

    <!-- 오른쪽 메인 영역 -->
    <main class="main-area">

        <!-- 상단 강아지 요약 카드 -->
        <section class="dog-summary-card">
            <div class="dog-summary-left">
                <div class="dog-summary-image-wrapper">
                    <img id="dogImage" src="<?php echo htmlspecialchars($dog_img); ?>" alt="강아지 사진">
                </div>
            </div>

            <div class="dog-summary-right">
                <div class="summary-row">
                    <div class="summary-label">이름</div>
                    <div class="summary-value" id="dogNameText">
                        : <?php echo htmlspecialchars($dog_name); ?>
                    </div>

                    <div class="summary-label">성별</div>
                    <div class="summary-value" id="dogGenderText">
                        : <?php echo htmlspecialchars($dog_gender); ?>
                    </div>
                </div>

                <div class="summary-row">
                    <div class="summary-label">품종</div>
                    <div class="summary-value" id="dogBreedText">
                        : <?php echo htmlspecialchars($dog_breed); ?>
                    </div>

                    <div class="summary-label">색</div>
                    <div class="summary-value" id="dogColorText">
                        : <?php echo htmlspecialchars($dog_color); ?>
                    </div>
                </div>

                <div class="summary-row">
                    <div class="summary-label">나이</div>
                    <div class="summary-value" id="dogAgeText">
                        : <?php echo htmlspecialchars($dog_age); ?> 살
                    </div>

                    <div class="summary-label">몸무게</div>
                    <div class="summary-value" id="dogWeightText">
                        : <?php echo htmlspecialchars($dog_weight); ?> kg
                    </div>
                </div>
            </div>
        </section>

        <!-- 신청자 테이블 (+ 완료 버튼을 감싸는 form) -->
        <form method="post" action="notice_detail.php">
            <input type="hidden" name="dog_id" value="<?php echo $dog_id; ?>">

            <section class="apply-table-wrapper">
                <table class="apply-table">
                    <thead>
                    <tr>
                        <th class="col-no">목록</th>
                        <th class="col-id">신청자 ID</th>
                        <th class="col-age">나이</th>
                        <th class="col-date">신청일</th>
                        <th class="col-result">심사 결과</th>
                    </tr>
                    </thead>

                    <tbody id="applicantTbody">
                    <?php if (empty($applicants)) : ?>
                        <tr>
                            <td colspan="5">입양 신청자가 없습니다.</td>
                        </tr>
                    <?php else :
                        $total = count($applicants);
                        foreach ($applicants as $i => $ap) :
                            $no       = $total - $i;
                            $req_id   = $ap['REQUEST_ID'];
                            $user_id  = $ap['USER_ID'];
                            $age      = $ap['AGE'];
                            $time     = $ap['REQ_TIME'];
                            $status   = $ap['STATUS'];   // PENDING / APPROVED / REJECTED

                            $checked = ($status === 'APPROVED');
                            $rowClass = $checked ? 'selected-row' : '';
                            ?>
                            <tr class="<?php echo $rowClass; ?>" data-request-id="<?php echo $req_id; ?>">
                                <td><?php echo $no; ?></td>
                                <td><?php echo htmlspecialchars($user_id); ?></td>
                                <td><?php echo htmlspecialchars($age); ?></td>
                                <td><?php echo htmlspecialchars($time); ?></td>
                                <td>
                                    <label class="result-label">
                                        <input type="radio"
                                               name="selected_request"
                                               value="<?php echo $req_id; ?>"
                                            <?php
                                            if ($checked) echo 'checked';
                                            if ($read_only) echo ' disabled';
                                            ?>>
                                        <span class="result-circle"></span>
                                    </label>
                                </td>
                            </tr>
                        <?php endforeach;
                    endif; ?>
                    </tbody>
                </table>
            </section>

            <!-- 하단 완료 버튼 -->
            <div class="bottom-actions">
                <?php if ($read_only || empty($applicants)) : ?>
                    <!-- 입양 완료 상태거나 신청자가 없으면 읽기 전용: 버튼 비활성화 -->
                    <button type="button"
                            class="complete-btn"
                            style="background-color:#777; cursor:default;"
                            onclick="alert('이미 입양 결과가 확정된 상태입니다.');">
                        완료
                    </button>
                <?php else : ?>
                    <button type="submit" class="complete-btn" id="completeBtn">완료</button>
                <?php endif; ?>
            </div>

        </form>

    </main>

</div>

<script>
    // radio 클릭 시 해당 행 하이라이트 (.selected-row)
    document.addEventListener("DOMContentLoaded", () => {
        const radios = document.querySelectorAll(".result-label input[type='radio']");
        radios.forEach(radio => {
            radio.addEventListener("change", () => {
                // 모든 행에서 selected-row 제거
                document.querySelectorAll(".apply-table tbody tr").forEach(tr => {
                    tr.classList.remove("selected-row");
                });
                // 선택된 radio가 속한 행에 selected-row 추가
                const tr = radio.closest("tr");
                if (tr) tr.classList.add("selected-row");
            });
        });
    });
</script>

</body>
</html>
