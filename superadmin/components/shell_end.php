        </main>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const toggle = document.getElementById('sa-sidebar-toggle');
    const sidebar = document.getElementById('sa-sidebar');

    if (!toggle || !sidebar) return;

    toggle.addEventListener('click', function () {
        sidebar.classList.toggle('is-open');
    });

    document.addEventListener('click', function (event) {
        const clickedInside = sidebar.contains(event.target) || toggle.contains(event.target);
        if (!clickedInside && sidebar.classList.contains('is-open')) {
            sidebar.classList.remove('is-open');
        }
    });
});
</script>
