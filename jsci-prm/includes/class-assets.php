<?php
/**
 * Asset registration and enqueueing.
 *
 * @package Snivertech\Sohan\JSCIPRM
 */

namespace Snivertech\Sohan\JSCIPRM;

defined( 'ABSPATH' ) || exit;

/**
 * Class Assets
 */
final class Assets {

    /**
     * Enqueue admin-side JS/CSS (on our own admin pages only).
     */
    public static function enqueue_admin( string $hook ): void {

        // Only load on JSCI PRM admin pages.
        if ( false === strpos( $hook, 'jsci-prm' ) ) {
            return;
        }

        wp_enqueue_style(
            'jsci-prm-admin',
            JSCI_PRM_URL . 'assets/css/admin.css',
            [],
            JSCI_PRM_VERSION
        );

        wp_enqueue_script(
            'jsci-prm-admin',
            JSCI_PRM_URL . 'assets/js/admin.js',
            [ 'wp-api-fetch', 'wp-i18n' ],
            JSCI_PRM_VERSION,
            true
        );

        // Pass PHP data to the JS app.
        wp_localize_script( 'jsci-prm-admin', 'jsciPrm', [
            'apiBase'   => rest_url( REST_Router::NAMESPACE ),
            'nonce'     => wp_create_nonce( 'wp_rest' ),
            'version'   => JSCI_PRM_VERSION,
            'currentUser' => [
                'id'          => get_current_user_id(),
                'role'        => implode( ', ', wp_get_current_user()->roles ),
                'caps'        => self::current_user_caps(),
                'orderAccess' => self::current_user_order_access(),
                'transactionAccess' => self::current_user_transaction_access(),
                'MessageAccess' => Access_Manager::user_can_manage_Messages( get_current_user_id() ),
            ],
            'accessManagement' => [
                'users'                 => self::access_management_users(),
                'caps'                  => self::access_management_caps(),
                'productionEntryMatrix' => self::access_management_production_entry_matrix(),
                'entryTypes'            => self::access_management_entry_types(),
                'orderAccessOptions'    => self::access_management_order_access_options(),
                'transactionAccessOptions' => self::access_management_transaction_access_options(),
                'MessageAccessOptions' => self::access_management_Message_access_options(),
                'roleOptions'           => self::access_management_role_options(),
            ],
        ] );
    }

    /**
     * Enqueue frontend assets for PRM shortcode pages (future use).
     */
    public static function enqueue_frontend(): void {
        // Frontend Message assets are printed directly in wp_head/wp_footer so
        // every theme template with a .Message placeholder can receive them.
    }

    public static function print_frontend_Message_styles(): void {
        if ( is_admin() ) {
            return;
        }

        echo '<style id="jsci-prm-Message-styles">' . self::frontend_Message_css() . '</style>';
    }

    public static function print_frontend_Message_script(): void {
        if ( is_admin() ) {
            return;
        }

        echo '<script id="jsci-prm-Message-script">window.jsciPrmMessages=' . wp_json_encode( [
            'activeUrl' => esc_url_raw( rest_url( REST_Router::NAMESPACE . '/Messages/active' ) ),
        ] ) . ';' . self::frontend_Message_js() . '</script>';
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private static function current_user_caps(): array {
        $caps   = [];
        foreach ( Roles::all_caps() as $cap ) {
            $caps[ $cap ] = current_user_can( $cap );
        }
        return $caps;
    }

    /**
     * Returns effective order management access for the current user.
     */
    private static function current_user_order_access(): array {
        $user_id = get_current_user_id();
        $access = [];

        foreach ( Access_Manager::order_management_access_keys() as $key ) {
            $access[ $key ] = Access_Manager::user_can_manage_order_action( $user_id, $key );
        }

        return $access;
    }

    /**
     * Returns effective transaction management access for the current user.
     */
    private static function current_user_transaction_access(): array {
        $user_id = get_current_user_id();
        $access = [];

        foreach ( Access_Manager::transaction_management_access_keys() as $key ) {
            $access[ $key ] = Access_Manager::user_can_manage_transaction_action( $user_id, $key );
        }

        return $access;
    }

    /**
     * Returns a compact user list for the access management admin page.
     */
    private static function access_management_users(): array {
        if ( ! Roles::current_user_can_manage_access_management() ) {
            return [];
        }

        $users = get_users( [
            'orderby' => 'display_name',
            'order'   => 'ASC',
        ] );

        return array_map( static function ( \WP_User $user ): array {
            $caps = [];

            foreach ( Roles::all_caps() as $cap ) {
                $caps[ $cap ] = user_can( $user, $cap );
            }

            $wp_roles = array_values( (array) $user->roles );
            $user_type = empty( $wp_roles )
                ? __( 'No role assigned', 'jsci-prm' )
                : implode( ', ', $wp_roles );

            return [
                'id'          => (int) $user->ID,
                'username'    => $user->user_login,
                'firstName'   => get_user_meta( (int) $user->ID, 'first_name', true ),
                'lastName'    => get_user_meta( (int) $user->ID, 'last_name', true ),
                'designation' => get_user_meta( (int) $user->ID, 'jsci_prm_designation', true ),
                'name'        => $user->display_name,
                'email'       => $user->user_email,
                'userType'    => $user_type,
                'wpRoles'     => $wp_roles,
                'caps'        => $caps,
                'inactive'    => Roles::user_is_inactive( (int) $user->ID ),
            ];
        }, $users );
    }

    /**
     * Returns PRM capabilities with labels for placeholder access controls.
     */
    private static function access_management_caps(): array {
        if ( ! Roles::current_user_can_manage_access_management() ) {
            return [];
        }

        $labels = Roles::cap_labels();

        return array_map( static function ( string $cap ) use ( $labels ): array {
            return [
                'key'   => $cap,
                'label' => $labels[ $cap ] ?? $cap,
            ];
        }, Roles::all_caps() );
    }

    /**
     * Returns departments and lines for production entry access management.
     */
    private static function access_management_production_entry_matrix(): array {
        global $wpdb;

        if ( ! Roles::current_user_can_manage_access_management() ) {
            return [];
        }

        $departments = $wpdb->get_results(
            "SELECT * FROM `" . Database::departments() . "` WHERE is_active = 1 ORDER BY workflow_order ASC, id ASC"
        ) ?: [];

        $lines = $wpdb->get_results(
            "SELECT * FROM `" . Database::department_lines() . "` WHERE is_active = 1 ORDER BY department_id ASC, sort_order ASC, id ASC"
        ) ?: [];

        $lines_by_department = [];

        foreach ( $lines as $line ) {
            $lines_by_department[ (int) $line->department_id ][] = [
                'line_number' => $line->line_number,
                'sort_order'  => (int) $line->sort_order,
            ];
        }

        return array_map( static function ( $department ) use ( $lines_by_department ): array {
            return [
                'id'    => (int) $department->id,
                'name'  => $department->name,
                'lines' => $lines_by_department[ (int) $department->id ] ?? [],
            ];
        }, $departments );
    }

    /**
     * Returns labels for production entry transaction types.
     */
    private static function access_management_entry_types(): array {
        if ( ! Roles::current_user_can_manage_access_management() ) {
            return [];
        }

        return [
            [
                'key'   => 'accept_transfers',
                'label' => __( 'Accept transfers', 'jsci-prm' ),
            ],
            [
                'key'   => 'receive',
                'label' => __( 'Receive', 'jsci-prm' ),
            ],
            [
                'key'   => 'produce',
                'label' => __( 'Production', 'jsci-prm' ),
            ],
            [
                'key'   => 'send',
                'label' => __( 'Send', 'jsci-prm' ),
            ],
            [
                'key'   => 'send_outside_factory',
                'label' => __( 'Send Outside Factory', 'jsci-prm' ),
            ],
            [
                'key'   => 'reject',
                'label' => __( 'Reject', 'jsci-prm' ),
            ],
        ];
    }

    /**
     * Returns labels for order management access controls.
     */
    private static function access_management_order_access_options(): array {
        if ( ! Roles::current_user_can_manage_access_management() ) {
            return [];
        }

        return [
            [
                'key'   => 'create',
                'label' => __( 'Create order', 'jsci-prm' ),
            ],
            [
                'key'   => 'edit',
                'label' => __( 'Edit order', 'jsci-prm' ),
            ],
            [
                'key'   => 'change_status',
                'label' => __( 'Change order status', 'jsci-prm' ),
            ],
            [
                'key'   => 'void',
                'label' => __( 'Void order', 'jsci-prm' ),
            ],
        ];
    }

    /**
     * Returns labels for transaction management access controls.
     */
    private static function access_management_transaction_access_options(): array {
        if ( ! Roles::current_user_can_manage_access_management() ) {
            return [];
        }

        return [
            [
                'key'   => 'edit',
                'label' => __( 'Edit transaction', 'jsci-prm' ),
            ],
            [
                'key'   => 'void',
                'label' => __( 'Void transaction', 'jsci-prm' ),
            ],
        ];
    }

    private static function access_management_Message_access_options(): array {
        if ( ! Roles::current_user_can_manage_access_management() ) {
            return [];
        }

        return [
            [
                'key'   => 'access',
                'label' => __( 'Access Message page', 'jsci-prm' ),
            ],
        ];
    }

    /**
     * Returns role choices for Access Management user forms.
     */
    private static function access_management_role_options(): array {
        if ( ! Roles::current_user_can_manage_access_management() ) {
            return [];
        }

        return [
            [
                'key'   => 'jsci_employee',
                'label' => __( 'JSCI Employee', 'jsci-prm' ),
            ],
            [
                'key'   => 'jsci_admin',
                'label' => __( 'JSCI Admin', 'jsci-prm' ),
            ],
            [
                'key'   => 'jsci_super_admin',
                'label' => __( 'JSCI Super Admin', 'jsci-prm' ),
            ],
        ];
    }

    private static function frontend_Message_css(): string {
        return '
.Message{display:none}
.Message.jsci-Message-visible{display:grid;gap:0px;margin:0 auto 0px;font-family:Arial,Helvetica,sans-serif}
.jsci-Message-row {overflow: hidden;background: #fff3cd;color: #000000;border: 2px solid #01c4c6;border-top: 2px solid #04b5b7;}
.jsci-Message-row--secondary{margin-top: -2px;background:#edf9f9;border-color:#01c4c6;color:#000;}
.jsci-Message-ticker{display:flex;align-items:center;gap:0;white-space:nowrap;min-height:44px}
.jsci-Message-label{z-index: 99;flex:0 0 auto;align-self:stretch;display:flex;align-items:center;padding:0 14px;background:#04b5b7;color:#fff;font-weight:800;text-transform:uppercase;letter-spacing:.05em;font-size:12px;cursor:help}
.jsci-Message-row--secondary .jsci-Message-label{background:#02d1d3;}
.jsci-Message-track-wrap{flex:1;overflow:hidden}
.jsci-Message-track{display:inline-block;padding:10px 0 10px 100%;font-size:17px;letter-spacing:0;animation:jsci-Message-crawl var(--jsci-Message-duration, 18s) linear infinite;}
.jsci-Message-creator-popover{position:fixed;z-index:999999;display:none;max-width:min(280px,calc(100vw - 24px));padding:10px 14px;border-radius:12px;background:#0f172a;color:#fff;box-shadow:0 14px 32px rgba(15,23,42,.28);font:700 13px/1.35 Arial,Helvetica,sans-serif;letter-spacing:0;text-transform:none;pointer-events:none}
.jsci-Message-creator-popover.is-visible{display:block}
.jsci-Message-creator-popover::before{content:"";position:absolute;top:-6px;left:18px;width:12px;height:12px;background:#0f172a;transform:rotate(45deg)}
@keyframes jsci-Message-crawl{0%{transform:translateX(0)}100%{transform:translateX(-100%)}}
@media (max-width:640px){.jsci-Message-track{font-size:15px}.jsci-Message-label{font-size:10px;padding:0 10px}}
';
    }

    private static function frontend_Message_js(): string {
        return '
(function(){
    var areas = Array.prototype.slice.call(document.querySelectorAll(".Message"));
    var activeItems = [];
    var loading = false;
    var observerTimer = null;
    var creatorPopover = null;
    var pinnedLabel = null;
    if (!window.jsciPrmMessages || !window.jsciPrmMessages.activeUrl) return;
    function hideAll(){areas = Array.prototype.slice.call(document.querySelectorAll(".Message"));areas.forEach(function(area){area.classList.remove("jsci-Message-visible");area.removeAttribute("data-jsci-Message-id");area.innerHTML="";area.style.display="none";});}
    function esc(text){var span=document.createElement("span");span.textContent=text || "";return span.innerHTML;}
    function normalizeItems(response){
        if (!response) return [];
        if (Array.isArray(response)) return response;
        if (Array.isArray(response.items)) return response.items;
        if (response.message) return [response];
        return [response.primary, response.secondary].filter(Boolean);
    }
    function setTrackSpeeds(area){
        window.requestAnimationFrame(function(){
            Array.prototype.slice.call(area.querySelectorAll(".jsci-Message-track")).forEach(function(track){
                var distance = Math.max(1, track.scrollWidth);
                var pixelsPerSecond = 100;
                var duration = Math.max(10, distance / pixelsPerSecond);
                track.style.setProperty("--jsci-Message-duration", duration.toFixed(2) + "s");
            });
        });
    }
    function getCreatorPopover(){
        if (!creatorPopover) {
            creatorPopover = document.createElement("div");
            creatorPopover.className = "jsci-Message-creator-popover";
            document.body.appendChild(creatorPopover);
        }
        return creatorPopover;
    }
    function showCreator(label, pin){
        var creator = label.getAttribute("data-creator-name") || "";
        if (!creator) return;
        var popover = getCreatorPopover();
        var rect = label.getBoundingClientRect();
        popover.textContent = creator;
        popover.classList.add("is-visible");
        var top = rect.bottom + 10;
        var left = Math.min(Math.max(12, rect.left), window.innerWidth - popover.offsetWidth - 12);
        popover.style.top = top + "px";
        popover.style.left = left + "px";
        if (pin) pinnedLabel = label;
    }
    function hideCreator(force){
        if (!creatorPopover || (!force && pinnedLabel)) return;
        creatorPopover.classList.remove("is-visible");
        if (force) pinnedLabel = null;
    }
    function renderItems(items){
        areas = Array.prototype.slice.call(document.querySelectorAll(".Message"));
        if (!areas.length) return;
        items = normalizeItems(items).filter(function(item){return item && item.message;});
        if (!items.length){hideAll();return;}
        var renderKey = items.map(function(item){return String(item.id) + ":" + (item.slot || "primary") + ":" + (item.user_designation || item.designation || item.user_name || "User");}).join("|");
        areas.forEach(function(area){
            if (area.getAttribute("data-jsci-Message-key") === renderKey) return;
            area.setAttribute("data-jsci-Message-key", renderKey);
            area.style.display="";
            area.classList.add("jsci-Message-visible");
            area.innerHTML = items.map(function(item){
                var slot = item.slot === "secondary" ? "secondary" : "primary";
                var designation = item.user_designation || item.designation || item.user_name || "User";
                var creator = item.user_full_name || item.user_name || "User";
                var label = "Message From: " + designation;
                return "<div class=\"jsci-Message-row jsci-Message-row--" + esc(slot) + "\"><div class=\"jsci-Message-ticker\"><span class=\"jsci-Message-label\" data-creator-name=\"" + esc(creator) + "\" tabindex=\"0\">" + esc(label) + "</span><span class=\"jsci-Message-track-wrap\"><span class=\"jsci-Message-track\">" + esc(item.message) + "</span></span></div></div>";
            }).join("");
            setTrackSpeeds(area);
        });
    }
    document.addEventListener("mouseover", function(event){
        var label = event.target.closest ? event.target.closest(".jsci-Message-label") : null;
        if (label) showCreator(label, false);
    });
    document.addEventListener("mouseout", function(event){
        var label = event.target.closest ? event.target.closest(".jsci-Message-label") : null;
        if (label && !label.contains(event.relatedTarget)) hideCreator(false);
    });
    document.addEventListener("focusin", function(event){
        if (event.target.classList && event.target.classList.contains("jsci-Message-label")) showCreator(event.target, false);
    });
    document.addEventListener("focusout", function(event){
        if (event.target.classList && event.target.classList.contains("jsci-Message-label")) hideCreator(false);
    });
    document.addEventListener("click", function(event){
        var label = event.target.closest ? event.target.closest(".jsci-Message-label") : null;
        if (label) {
            if (pinnedLabel === label) {
                hideCreator(true);
            } else {
                showCreator(label, true);
            }
            return;
        }
        hideCreator(true);
    });
    window.jsciLoadMessages = function(){
        areas = Array.prototype.slice.call(document.querySelectorAll(".Message"));
        if (loading) return;
        loading = true;
        fetch(window.jsciPrmMessages.activeUrl,{credentials:"same-origin"})
        .then(function(res){return res.ok ? res.json() : null;})
        .then(function(response){
            activeItems = normalizeItems(response);
            renderItems(activeItems);
            loading = false;
        })
        .catch(function(){loading = false;hideAll();});
    };
    window.jsciLoadMessages();
    if (window.MutationObserver) {
        new MutationObserver(function(){
            clearTimeout(observerTimer);
            observerTimer = setTimeout(function(){
                areas = Array.prototype.slice.call(document.querySelectorAll(".Message"));
                if (!areas.length) return;
                if (activeItems.length) {
                    renderItems(activeItems);
                } else {
                    window.jsciLoadMessages();
                }
            }, 100);
        }).observe(document.body, {childList:true, subtree:true});
    } else {
        setInterval(window.jsciLoadMessages, 2000);
    }
})();
';
    }
}
