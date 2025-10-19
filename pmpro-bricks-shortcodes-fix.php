<?php
/**
 * Plugin Name: IECT – PMPro + Bricks: asegurar shortcodes
 * Description: Registra los shortcodes de PMPro si aún no están cargados, para que Bricks no los imprima como texto.
 * Author: IECT
 */
if (!defined('ABSPATH')) exit;

/**
 * Asegura que el shortcode [pmpro_confirmation] esté disponible
 * antes de que Bricks renderice. Basado en el snippet oficial de PMPro.
 * Fuente: pmpro-snippets-library / integration-compatibility (Stranger Studios).
 */
add_action('plugins_loaded', function () {
    // Si ya existe, no hacemos nada.
    if (shortcode_exists('pmpro_confirmation')) {
        return;
    }
    if (!defined('PMPRO_DIR')) {
        return; // PMPro no está activo.
    }

    /**
     * Registramos un "fallback" del shortcode que ejecuta el preheader
     * y renderiza la plantilla de confirmación de PMPro.
     * Esto imita cómo lo hace PMPro internamente.
     */
    add_shortcode('pmpro_confirmation', function($atts = [], $content = '', $tag = '') {
        // Variables globales que usa PMPro en sus plantillas.
        global $pmpro_msg, $pmpro_msgt, $current_user, $pmpro_levels, $pmpro_invoice;

        // Ejecuta la lógica previa de confirmación (setea $pmpro_invoice, etc.).
        @require_once PMPRO_DIR . '/preheaders/confirmation.php';

        // Renderiza la plantilla estándar de confirmación y la devuelve como string.
        ob_start();
        if (function_exists('pmpro_loadTemplate')) {
            pmpro_loadTemplate('confirmation', 'local', 'pages');
        } else {
            // Fallback muy defensivo (para versiones antiguas).
            $tpl = PMPRO_DIR . '/pages/confirmation.php';
            if (file_exists($tpl)) {
                include $tpl;
            }
        }
        return ob_get_clean();
    });
}, 20);
