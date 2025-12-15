// user-profile.js
// 입양 희망자 회원정보 수정 페이지 JavaScript

let currentGender = null; // 'M' 또는 'F'

// 1) 시/도 - 구/군 목록 정의
const districts = {
    "서울특별시": [
        "강남구", "강동구", "강북구", "강서구",
        "관악구", "광진구", "구로구", "금천구",
        "노원구", "도봉구", "동대문구", "동작구",
        "마포구", "서대문구", "서초구", "성동구",
        "성북구", "송파구", "양천구", "영등포구",
        "용산구", "은평구", "종로구", "중구", "중랑구"
    ],
    "경기도 수원시": ["장안구", "권선구", "팔달구", "영통구"],
    "경기도 고양시": ["덕양구", "일산동구", "일산서구"],
    "대구광역시": ["남구", "달서구", "달성군", "동구", "북구", "서구", "수성구", "중구"]
};

// ===========================
// 초기화
// ===========================
document.addEventListener('DOMContentLoaded', function() {
    const form      = document.getElementById('userProfileForm');
    const deleteBtn = document.getElementById('deleteBtn');
    const logoutBtn = document.getElementById('logoutBtn');
    const maleBtn   = document.getElementById('maleBtn');
    const femaleBtn = document.getElementById('femaleBtn');
    const phoneInput= document.getElementById('phone');

    // 시/도 옵션 채우기
    fillCityOptions(null);

    // 이벤트 등록
    form.addEventListener('submit', handleSubmit);
    deleteBtn.addEventListener('click', handleDelete);
    logoutBtn.addEventListener('click', handleLogout);

    maleBtn.addEventListener('click', () => selectGender('M'));
    femaleBtn.addEventListener('click', () => selectGender('F'));

    phoneInput.addEventListener('input', formatPhoneNumber);

    const citySelect = document.getElementById('residence1');
    citySelect.addEventListener('change', () => {
        const city = citySelect.value;
        fillDistrictOptions(city, null);
    });

    // 서버에서 기존 데이터 불러오기
    loadUserData();
});

// ===========================
//  사용자 데이터 불러오기
// ===========================
function loadUserData() {
    fetch('user_profile.php?mode=load')
        .then(res => res.json())
        .then(data => {
            if (data.error) {
                alert(data.error + (data.detail ? "\n\n" + data.detail : ""));
                console.error(data);
                return;
            }

            document.getElementById('userId').value  = data.user_id;
            document.getElementById('name').value    = data.name;
            document.getElementById('phone').value   = data.phone || '';
            document.getElementById('birthYear').value  = data.birthYear || '';
            document.getElementById('birthMonth').value = data.birthMonth || '';
            document.getElementById('birthDay').value   = data.birthDay || '';

            const city     = data.city     || '';
            const district = data.district || '';

            fillCityOptions(city);
            fillDistrictOptions(city, district);

            const profileNameEl = document.querySelector('.profile-name');
            if (profileNameEl && data.name) {
                profileNameEl.textContent = data.name + ' 님';
            }

            if (data.gender === 'M') {
                selectGender('M');
            } else if (data.gender === 'F') {
                selectGender('F');
            }
        })
        .catch(err => {
            console.error(err);
            alert('회원 정보를 불러오는 중 오류가 발생했습니다.');
        });
}

// ===========================
//  전화번호 포맷팅 (010-0000-0000)
// ===========================
function formatPhoneNumber(e) {
    let value = e.target.value.replace(/[^0-9]/g, '');

    if (value.length <= 3) {
        e.target.value = value;
    } else if (value.length <= 7) {
        e.target.value = value.slice(0, 3) + '-' + value.slice(3);
    } else if (value.length <= 11) {
        e.target.value =
            value.slice(0, 3) + '-' +
            value.slice(3, 7) + '-' +
            value.slice(7);
    } else {
        e.target.value =
            value.slice(0, 3) + '-' +
            value.slice(3, 7) + '-' +
            value.slice(7, 11);
    }
}

// ===========================
//  성별 선택
// ===========================
function selectGender(genderCode) {
    currentGender = genderCode; // 'M' 또는 'F'

    const maleBtn   = document.getElementById('maleBtn');
    const femaleBtn = document.getElementById('femaleBtn');

    maleBtn.classList.remove('selected');
    femaleBtn.classList.remove('selected');

    if (genderCode === 'M') {
        maleBtn.classList.add('selected');
    } else if (genderCode === 'F') {
        femaleBtn.classList.add('selected');
    }
}

// ===========================
//  거주지 드롭다운
// ===========================
function fillCityOptions(selectedCity) {
    const citySelect = document.getElementById('residence1');
    citySelect.innerHTML = '<option value="">시/도 선택</option>';

    Object.keys(districts).forEach(city => {
        const opt = document.createElement('option');
        opt.value = city;
        opt.textContent = city;
        citySelect.appendChild(opt);
    });

    if (selectedCity && !districts[selectedCity]) {
        const opt = document.createElement('option');
        opt.value = selectedCity;
        opt.textContent = selectedCity;
        citySelect.appendChild(opt);
    }

    if (selectedCity) {
        citySelect.value = selectedCity;
    }
}

function fillDistrictOptions(city, selectedDistrict) {
    const districtSelect = document.getElementById('residence2');
    districtSelect.innerHTML = '<option value="">구/군 선택</option>';

    const list = districts[city];
    if (list && list.length > 0) {
        list.forEach(d => {
            const opt = document.createElement('option');
            opt.value = d;
            opt.textContent = d;
            districtSelect.appendChild(opt);
        });
    } else if (selectedDistrict) {
        const opt = document.createElement('option');
        opt.value = selectedDistrict;
        opt.textContent = selectedDistrict;
        districtSelect.appendChild(opt);
    }

    if (selectedDistrict) {
        districtSelect.value = selectedDistrict;
    }
}

// ===========================
//  폼 제출 (수정)
// ===========================
function handleSubmit(e) {
    e.preventDefault();

    const phone     = document.getElementById('phone').value.trim();
    const password  = document.getElementById('password').value;
    const city      = document.getElementById('residence1').value;
    const district  = document.getElementById('residence2').value;

    if (!phone) {
        alert('전화번호를 입력해주세요.');
        return;
    }
    if (!city || !district) {
        alert('거주지를 모두 선택해주세요.');
        return;
    }
    if (!currentGender) {
        alert('성별을 선택해주세요.');
        return;
    }

    const formData = new URLSearchParams();
    formData.append('action',   'update');
    formData.append('password', password);
    formData.append('phone',    phone);
    formData.append('gender',   currentGender); // 'M' 또는 'F'
    formData.append('city',     city);
    formData.append('district', district);

    fetch('user_profile.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: formData
    })
        .then(res => res.json())
        .then(data => {
            if (data.error) {
                alert(data.error + (data.detail ? "\n\n" + data.detail : ""));
                console.error(data);
                return;
            }
            alert('회원정보가 수정되었습니다.');
            document.getElementById('password').value = '';
        })
        .catch(err => {
            console.error(err);
            alert('회원 정보 수정 중 오류가 발생했습니다.');
        });
}

// ===========================
//  회원 탈퇴
// ===========================
function handleDelete() {
    if (!confirm('정말로 탈퇴하시겠습니까?\n탈퇴 시 모든 정보가 삭제되며 복구할 수 없습니다.')) {
        return;
    }
    if (!confirm('다시 한 번 확인합니다. 탈퇴하시겠습니까?')) {
        return;
    }

    const formData = new URLSearchParams();
    formData.append('action', 'delete');

    fetch('user_profile.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: formData
    })
        .then(res => res.json())
        .then(data => {
            if (data.error) {
                alert(data.error + (data.detail ? "\n\n" + data.detail : ""));
                console.error(data);
                return;
            }
            alert('회원 탈퇴가 완료되었습니다.');
            window.location.href = '../index.html';
        })
        .catch(err => {
            console.error(err);
            alert('회원 탈퇴 처리 중 오류가 발생했습니다.');
        });
}


// ===========================
//  로그아웃
// ===========================
function handleLogout() {
    if (!confirm('로그아웃 하시겠습니까?')) return;
    window.location.href = '../login/logout.php';
}
