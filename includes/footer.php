    </main>
    </div>

    <footer class="main-footer">
        <p>&copy; <?php echo date("Y"); ?> STME NMIMS, Hyderabad. All Rights Reserved.</p>
    </footer>

    <script src="https://cdnjs.cloudflare.com/ajax/libs/jquery/3.6.0/jquery.min.js"></script>
    
    <!-- DataTables for Sorting & Searching -->
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/jquery.dataTables.min.css">
    <script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>

    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
    <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>

    <script>
        // Define global base URL for JS files
        const SITE_BASE_URL = "<?php echo $base_url; ?>";

        $(document).ready(function() {
            // Initialize DataTables on standard tables while tolerating simple colspan-based empty rows.
            $('.data-table:not(.custom-table)').each(function() {
                const $table = $(this);

                if ($.fn.DataTable.isDataTable($table)) {
                    return;
                }

                const $tbody = $table.find('tbody');
                const $rows = $tbody.find('tr');
                const $colspanCells = $tbody.find('td[colspan]');
                let emptyMessage = 'No records found.';

                // Many pages render one placeholder row with a single colspan cell.
                // DataTables treats that as an invalid column count, so remove it and use emptyTable text.
                if ($rows.length === 1 && $colspanCells.length === 1) {
                    emptyMessage = $.trim($colspanCells.first().text()) || emptyMessage;
                    $rows.remove();
                }

                $table.DataTable({
                    "paging": true,
                    "ordering": true,
                    "info": true,
                    "searching": true,
                    "pageLength": 20,
                    "lengthMenu": [10, 20, 50, 100],
                    "language": {
                        "search": "Search records:",
                        "lengthMenu": "Show _MENU_ entries",
                        "emptyTable": emptyMessage
                    }
                });
            });
        });
    </script>

    <?php if(isset($_SESSION['role']) && $_SESSION['role'] === 'superadmin'): ?>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/chartjs-plugin-datalabels@2"></script>
    <script src="https://cdn.jsdelivr.net/npm/chartjs-adapter-date-fns/dist/chartjs-adapter-date-fns.bundle.min.js"></script>
    <script src="<?php echo $base_url; ?>/assets/js/superadmin_final.js"></script>
    <?php else: ?>
    <script src="<?php echo $base_url; ?>/assets/js/main.js?v=<?php echo time(); ?>"></script>
    <?php endif; ?>

    <?php if(isset($_SESSION['role']) && in_array($_SESSION['role'], ['manager', 'superadmin'])): ?>
    <script src="<?php echo $base_url; ?>/assets/js/enableSmoothScroll.js?v=<?php echo time(); ?>"></script>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            if (typeof window.enableSmoothScroll === 'function') {
                window.enableSmoothScroll({
                    speed: 0.95,
                    ease: 0.15,
                    progressIndicator: true,
                    progressColor: '<?php echo $_SESSION['role'] === 'superadmin' ? '#ff5ea8' : '#c754ff'; ?>'
                });
            }
        });
    </script>
    <?php endif; ?>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            var body = document.body;
            var sidebar = document.getElementById('main-nav');
            var toggleBtn = document.getElementById('sidebar-toggle');
            var storageKey = 'dic_sidebar_collapsed';

            if (!sidebar || !toggleBtn) {
                return;
            }

            var mediaQuery = window.matchMedia('(max-width: 1024px)');

            function applyStoredState() {
                if (mediaQuery.matches) {
                    body.classList.remove('sidebar-collapsed');
                    return;
                }
                var isCollapsed = localStorage.getItem(storageKey) === '1';
                body.classList.toggle('sidebar-collapsed', isCollapsed);
            }

            function closeMobileSidebar() {
                body.classList.remove('sidebar-open');
            }

            toggleBtn.addEventListener('click', function(e) {
                e.stopPropagation();
                if (mediaQuery.matches) {
                    body.classList.toggle('sidebar-open');
                    return;
                }

                body.classList.toggle('sidebar-collapsed');
                localStorage.setItem(storageKey, body.classList.contains('sidebar-collapsed') ? '1' : '0');
            });

            document.addEventListener('click', function(e) {
                if (!mediaQuery.matches || !body.classList.contains('sidebar-open')) {
                    return;
                }

                var clickedInsideSidebar = sidebar.contains(e.target);
                var clickedToggle = toggleBtn.contains(e.target);
                if (!clickedInsideSidebar && !clickedToggle) {
                    closeMobileSidebar();
                }
            });

            sidebar.querySelectorAll('a').forEach(function(link) {
                link.addEventListener('click', function() {
                    if (mediaQuery.matches) {
                        closeMobileSidebar();
                    }
                });
            });

            if (mediaQuery.addEventListener) {
                mediaQuery.addEventListener('change', function() {
                    body.classList.remove('sidebar-open');
                    applyStoredState();
                });
            } else if (mediaQuery.addListener) {
                mediaQuery.addListener(function() {
                    body.classList.remove('sidebar-open');
                    applyStoredState();
                });
            }

            applyStoredState();
        });
    </script>

</body>
</html>