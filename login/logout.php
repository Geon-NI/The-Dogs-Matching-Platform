<?php
session_start();

// 모든 세션 변수 비우기
session_unset();

// 세션 자체 파기
session_destroy();

// 로그아웃 후 이동
echo "<script>
    alert('로그아웃 되었습니다.');
    window.location.href = '../index.html';
</script>";
exit;
?>
