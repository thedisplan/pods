<?php
    if ( !empty( $_POST ) ) {
        if ( isset( $_POST[ 'clearcache' ] ) ) {
            pods_api()->cache_flush_pods();

            pods_redirect( pods_var_update( array( 'pods_clearcache' => 1 ), array( 'page', 'tab' ) ) );
        }
    }
    elseif ( 1 == pods_var( 'pods_clearcache' ) )
        pods_ui_message( 'Pods 2.0 transients and cache have been cleared.' );

    if ( PODS_GITHUB_UPDATE ) {
?>

<h3><?php _e( 'Force an update of this beta from GitHub', 'pods' ); ?></h3>

<p><?php _e( 'This tool lets you update your Pods 2.0 beta installation to the latest, usually only when you\'ve been instructed to do so.', 'pods' ); ?></p>

<?php
    $update = admin_url( 'update-core.php?pods_force_refresh=1' );

    if ( is_multisite() )
        $update = network_admin_url( 'update-core.php?pods_force_refresh=1' );
?>

<p class="submit">
    <a href="<?php echo $update; ?>" class="button button-primary"><?php esc_html_e( 'Force Plugin Refresh/Update from GitHub', 'pods' ); ?></a>
</p>

<hr />

<?php } ?>

<h3><?php _e( 'Clear Pods 2.0 Cache', 'pods' ); ?></h3>

<p><?php _e( 'This tool will clear all of the transients/cache that are used by Pods 2.0. ', 'pods' ); ?></p>

<p class="submit">
    <input type="submit" class="button button-primary" name="clearcache" value="<?php esc_attr_e( 'Clear Pods 2.0 Cache', 'pods' ); ?>" />
</p>

<hr />

<h3><?php _e( 'Debug Information', 'pods' ); ?></h3>

<?php
    global $wp_version, $wpdb;

    $wp = $wp_version;
    $php = phpversion();
    $mysql = $wpdb->db_version();
    $plugins = array();

    $all_plugins = get_plugins();

    foreach ( $all_plugins as $plugin_file => $plugin_data ) {
        if ( is_plugin_active( $plugin_file ) )
            $plugins[ $plugin_data[ 'Name' ] ] = $plugin_data[ 'Version' ];
    }

    $versions = array(
        'WordPress Version' => $wp,
        'PHP Version' => $php,
        'MySQL Version' => $mysql,
        'Server Software' => $_SERVER[ 'SERVER_SOFTWARE' ],
        'Your User Agent' => $_SERVER[ 'HTTP_USER_AGENT' ],
        'Currently Active Plugins' => $plugins
    );

    foreach ( $versions as $what => $version ) {
        echo '<p><strong>' . $what . '</strong>: ';

        if ( is_array( $version ) ) {
            echo '</p><ul class="ul-disc">';

            foreach ( $version as $what_v => $v ) {
                echo '<li><strong>' . $what_v . '</strong>: ' . $v . '</li>';
            }

            echo '</ul>';
        }
        else
            echo $version . '</p>';
    }