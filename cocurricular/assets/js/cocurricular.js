document.addEventListener('DOMContentLoaded', function () {
    var clubs = window.cocurricularClubs || [];
    if (!Array.isArray(clubs) || clubs.length === 0) {
        return;
    }

    var modalEl = document.getElementById('cocurricularClubDetailModal');
    if (!modalEl || typeof bootstrap === 'undefined') {
        return;
    }

    var detailModal = new bootstrap.Modal(modalEl);

    function getClubById(clubId) {
        return clubs.find(function (club) {
            return parseInt(club.id, 10) === parseInt(clubId, 10);
        }) || null;
    }

    function formatList(items) {
        if (!Array.isArray(items) || items.length === 0) {
            return '<span class="text-muted">No officers listed.</span>';
        }
        return items.map(function (officer) {
            return '<li class="mb-2"><strong>' + officer.position + '</strong>: ' + officer.officer_name + '</li>';
        }).join('');
    }

    document.querySelectorAll('[data-cocurricular-club-id]').forEach(function (button) {
        button.addEventListener('click', function () {
            var clubId = button.getAttribute('data-cocurricular-club-id');
            var club = getClubById(clubId);
            if (!club) {
                return;
            }

            modalEl.querySelector('[data-club-name]').textContent = club.club_name || 'Club details';
            modalEl.querySelector('[data-club-category]').textContent = club.category || 'N/A';
            modalEl.querySelector('[data-club-status]').textContent = club.status || 'N/A';
            modalEl.querySelector('[data-club-status]').className = 'badge rounded-pill bg-' + (club.status === 'Active' ? 'success' : club.status === 'Pending' ? 'warning' : 'secondary') + ' text-uppercase';
            modalEl.querySelector('[data-club-description]').textContent = club.description || 'No description available.';
            modalEl.querySelector('[data-club-adviser]').textContent = club.adviser || 'N/A';
            modalEl.querySelector('[data-club-email]').textContent = club.adviser_email || 'N/A';
            modalEl.querySelector('[data-club-contact]').textContent = club.contact_phone || 'N/A';
            modalEl.querySelector('[data-club-members]').textContent = typeof club.member_count !== 'undefined' ? club.member_count : '0';
            modalEl.querySelector('[data-club-officers]').innerHTML = formatList(club.officers || []);

            var joinButton = modalEl.querySelector('[data-join-href]');
            joinButton.href = joinButton.getAttribute('data-base-url') + '/modules/cocurricular/pages/club-registration-portal.php?club_id=' + encodeURIComponent(club.id);
            if (club.status !== 'Active') {
                joinButton.classList.add('disabled');
                joinButton.setAttribute('aria-disabled', 'true');
                joinButton.textContent = 'Not open for registration';
            } else {
                joinButton.classList.remove('disabled');
                joinButton.removeAttribute('aria-disabled');
                joinButton.textContent = 'Join Club';
            }

            detailModal.show();
        });
    });

    var registrationForm = document.getElementById('cocurricularRegistrationForm');
    if (registrationForm) {
        var nextButton = document.getElementById('cocurricularNextBtn');
        var backButton = document.getElementById('cocurricularBackBtn');
        var stepIndicators = Array.from(document.querySelectorAll('[data-step-indicator]'));
        var stepPanels = Array.from(document.querySelectorAll('[data-step-panel]'));
        var reviewReason = document.getElementById('reviewReason');
        var reviewInterest = document.getElementById('reviewInterest');
        var reviewParticipation = document.getElementById('reviewParticipation');
        var reasonField = document.getElementById('reason_for_joining');
        var interestField = document.getElementById('areas_of_interest');
        var participationField = document.getElementById('preferred_participation');
        var agreementField = document.getElementById('agreement');

        function activateStep(step) {
            stepIndicators.forEach(function (indicator, index) {
                indicator.classList.toggle('is-active', index + 1 === step);
            });
            stepPanels.forEach(function (panel, index) {
                var active = index + 1 === step;
                panel.classList.toggle('is-active', active);
                panel.hidden = !active;
            });
        }

        function isAgreementChecked(el) {
            if (!el) return false;
            try {
                if (el.type === 'checkbox') return !!el.checked;
            } catch (e) {}
            var v = (el.value || '').toString();
            return v === '1' || v === 'true' || v.toLowerCase() === 'on';
        }

        nextButton?.addEventListener('click', function () {
            if (!reasonField.value.trim() || !interestField.value.trim() || !participationField.value || !isAgreementChecked(agreementField)) {
                window.scrollTo({ top: 0, behavior: 'smooth' });
                return;
            }
            reviewReason.textContent = reasonField.value.trim();
            reviewInterest.textContent = interestField.value.trim();
            reviewParticipation.textContent = participationField.value;
            activateStep(2);
        });

        backButton?.addEventListener('click', function () {
            activateStep(1);
        });
    }
});
