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
            // Initialize DataTables on all tables with class 'data-table' but exclude 'custom-table'
            $('.data-table:not(.custom-table)').DataTable({
                "paging": true,
                "ordering": true,
                "info": true,
                "searching": true,
                "pageLength": 20,
                "lengthMenu": [10, 20, 50, 100],
                "language": {
                    "search": "Search records:",
                    "lengthMenu": "Show _MENU_ entries"
                }
            });
        });
    </script>

    <?php if(isset($_SESSION['role']) && $_SESSION['role'] === 'superadmin'): ?>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/chartjs-adapter-date-fns/dist/chartjs-adapter-date-fns.bundle.min.js"></script>
    <script src="<?php echo $base_url; ?>/assets/js/superadmin_final.js"></script>
    <?php else: ?>
    <script src="<?php echo $base_url; ?>/assets/js/main.js"></script>
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

</body>
</html>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const menuToggle = document.getElementById('mobile-menu-toggle');
            const navMenu = document.querySelector('.main-navbar ul');
            
            if (menuToggle && navMenu) {
                menuToggle.addEventListener('click', function(e) {
                    e.stopPropagation();
                    navMenu.classList.toggle('show');
                });
                
                // Close menu when clicking outside
                document.addEventListener('click', function(e) {
                    if (!navMenu.contains(e.target) && !menuToggle.contains(e.target) && navMenu.classList.contains('show')) {
                        navMenu.classList.remove('show');
                    }
                });

                // Close menu when navigating via links
                navMenu.querySelectorAll('a').forEach(function(link) {
                    link.addEventListener('click', function() {
                        if (navMenu.classList.contains('show')) {
                            navMenu.classList.remove('show');
                        }
                    });
                });
            }
        });
    </script>