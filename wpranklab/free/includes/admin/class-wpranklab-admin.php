<?php
// Assuming existing content, only modified parts are shown below:

function render_dashboard_page() {
    // New HTML structure with updated classes
    echo '<div class="wpranklab-wrap">
            <div class="wpranklab-card wpranklab-grid-2">
                <h2>Dashboard</h2>
                <!-- Other dashboard content -->
            </div>
          </div>';
}

function wpranklab_render_header() {
    echo '<header class="wpranklab-muted">
            <h1>Welcome to the Dashboard</h1>
          </header>';
}
?>