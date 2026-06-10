/**
 * JSCI PRM – Admin JavaScript application bootstrap.
 * Namespace: Snivertech\Sohan\JSCIPRM
 *
 * Vanilla JS SPA that mounts into #jsci-prm-app.
 * Uses the WordPress REST API via the nonce provided by wp_localize_script.
 */

/* global jsciPrm, wpApiFetch */

( function () {
    'use strict';

    // ── Config ────────────────────────────────────────────────────────────────

    const API   = jsciPrm.apiBase;
    const NONCE = jsciPrm.nonce;
    let jsciOrderStatusFilter = '';
    let jsciShowVoidedOrders = false;
    let jsciOrdersPage = 1;
    let jsciOrderSearch = '';
    let jsciTransactionsView = 'active';
    let jsciTransactionsPage = 1;
    let jsciTransactionTypeFilter = '';
    let jsciTransactionDepartmentFilter = '';
    let jsciTransactionCreatedDateFilter = '';

    // ── API helper ────────────────────────────────────────────────────────────

    async function api( path, options = {} ) {
        const url     = API + path;
        const headers = {
            'Content-Type': 'application/json',
            'X-WP-Nonce':   NONCE,
            ...( options.headers || {} ),
        };

        const res = await fetch( url, {
            ...options,
            headers,
            body: options.body ? JSON.stringify( options.body ) : undefined,
        } );

        if ( ! res.ok ) {
            const err = await res.json().catch( () => ({ message: res.statusText }) );
            throw new Error( err.message || 'API error' );
        }

        const data = res.status === 204 ? null : await res.json();

        if ( options.returnMeta ) {
            return {
                data,
                meta: {
                    total: parseInt( res.headers.get( 'X-WP-Total' ) || '0', 10 ) || 0,
                    totalPages: parseInt( res.headers.get( 'X-WP-TotalPages' ) || '1', 10 ) || 1,
                },
            };
        }

        return data;
    }

    // ── Renderer ──────────────────────────────────────────────────────────────

    const $app = document.getElementById( 'jsci-prm-app' );
    if ( ! $app ) return;

    const page = $app.dataset.page || 'dashboard';

    switch ( page ) {
        case 'dashboard':
            renderDashboard();
            break;
        case 'orders':
            renderOrders();
            break;
        case 'transactions':
            renderTransactions();
            break;
        case 'departments':
            renderDepartments();
            break;
        case 'external-organizations':
            renderExternalOrganizations();
            break;
        case 'reports':
            renderReports();
            break;
        case 'access-management':
            renderAccessManagement();
            break;
        case 'audit':
            renderAudit();
            break;
        case 'settings':
            renderSettings();
            break;
        default:
            $app.innerHTML = '<p>Page not found.</p>';
    }

    // ── Dashboard ─────────────────────────────────────────────────────────────

    async function renderDashboard() {
        $app.innerHTML = loader();

        try {
            const [ orders, transactions, departments ] = await Promise.all( [
                api( '/orders?per_page=5&status=active' ),
                api( '/transactions?per_page=10' ),
                api( '/departments' ),
            ] );

            $app.innerHTML = `
                <div class="jsci-prm-wrap">
                    <h1>JSCI PRM Dashboard</h1>
                    <div class="jsci-prm-grid">
                        ${ statCard( 'Active orders',       orders.length ) }
                        ${ statCard( 'Departments',         departments.length ) }
                        ${ statCard( 'Recent transactions', transactions.length ) }
                    </div>

                    <h2 style="margin-top:32px">Recent transactions</h2>
                    ${ txTable( transactions ) }
                    <div id="jsci-transaction-form-wrap"></div>
                </div>
            `;
        } catch ( err ) {
            $app.innerHTML = notice( err.message, 'error' );
        }
    }

    // ── Orders ────────────────────────────────────────────────────────────────

    async function renderOrders() {

        $app.innerHTML = loader();

        try {

            const style = document.createElement('style');

            style.innerHTML = `
                .jsci-view {
                    opacity: 0;
                    transform: translateY(18px);
                    transition:
                        opacity .24s ease,
                        transform .24s ease;
                    pointer-events: none;
                    display: none;
                }

                .jsci-view--active {
                    display: block;
                    opacity: 1;
                    transform: translateY(0);
                    pointer-events: auto;
                }

                .jsci-view--leaving {
                    opacity: 0;
                    transform: translateY(12px);
                    pointer-events: none;
                }
            `;

            document.head.appendChild(style);

            const orderParams = new URLSearchParams( {
                per_page: '25',
                page: String( jsciOrdersPage ),
                status: jsciShowVoidedOrders ? 'voided' : jsciOrderStatusFilter,
            } );

            if ( jsciOrderSearch ) {
                orderParams.set( 'search', jsciOrderSearch );
            }

            const ordersResponse = await api( '/orders?' + orderParams.toString(), { returnMeta: true } );
            const orders = ordersResponse.data;
            const totalPages = Math.max( 1, ordersResponse.meta.totalPages );
            const currentPage = Math.min( jsciOrdersPage, totalPages );

            if ( currentPage !== jsciOrdersPage ) {
                jsciOrdersPage = currentPage;
                return renderOrders();
            }

            $app.innerHTML = `
                <div class="jsci-prm-wrap">

                    <!-- ORDER LIST -->
                    <div id="jsci-orders-list-view" class="jsci-view jsci-view--active">

                        <div style="
                            display:flex;
                            align-items:center;
                            justify-content:space-between;
                            margin-bottom:16px;
                            gap:16px;
                            flex-wrap:wrap;
                        ">

                            <div>
                                <h1 style="margin:0">Orders</h1>
                                <p style="margin:8px 0 0;color:#64748b">
                                    ${ jsciShowVoidedOrders ? 'Voided orders' : 'Production orders' }
                                </p>
                            </div>

                            <div style="display:flex;gap:12px;align-items:center;flex-wrap:wrap">
                                <form id="jsci-order-search-form" style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">
                                    <input
                                        type="search"
                                        id="jsci-order-search"
                                        class="regular-text"
                                        placeholder="Search buyer, order, reference"
                                        value="${ esc( jsciOrderSearch ) }"
                                        style="min-height:36px;min-width:260px">
                                    <button
                                        type="submit"
                                        class="jsci-btn jsci-btn--secondary">
                                        Search
                                    </button>
                                    ${
                                        jsciOrderSearch
                                            ? `
                                                <button
                                                    type="button"
                                                    class="jsci-btn jsci-btn--secondary"
                                                    id="jsci-order-search-clear">
                                                    Clear
                                                </button>
                                            `
                                            : ''
                                    }
                                </form>

                                ${
                                    jsciShowVoidedOrders
                                        ? `
                                            <button
                                                type="button"
                                                class="jsci-btn jsci-btn--secondary"
                                                id="jsci-voided-back-btn">
                                                Back to Orders
                                            </button>
                                        `
                                        : `
                                            <select id="jsci-order-status-filter"
                                                    class="jsci-filter-select">

                                                <option value="" ${jsciOrderStatusFilter === '' ? 'selected' : ''}>All Status</option>
                                                <option value="active" ${jsciOrderStatusFilter === 'active' ? 'selected' : ''}>Active</option>
                                                <option value="on_hold" ${jsciOrderStatusFilter === 'on_hold' ? 'selected' : ''}>On Hold</option>
                                                <option value="completed" ${jsciOrderStatusFilter === 'completed' ? 'selected' : ''}>Completed</option>
                                                <option value="cancelled" ${jsciOrderStatusFilter === 'cancelled' ? 'selected' : ''}>Cancelled</option>

                                            </select>
                                        `
                                }

                                <button
                                    type="button"
                                    class="jsci-btn jsci-btn--secondary"
                                    id="jsci-voided-orders-btn">
                                    Voided Orders
                                </button>

                                ${
                                    jsciPrm.currentUser.orderAccess?.create
                                        ? `
                                            <button
                                                class="jsci-btn jsci-btn--primary"
                                                id="jsci-new-order">
                                                + New Order
                                            </button>
                                        `
                                        : ''
                                }
                            </div>

                        </div>

                        ${ ordersTable( orders ) }
                        ${ renderOrdersPagination( currentPage, totalPages, ordersResponse.meta.total ) }

                    </div>

                    <!-- FORM VIEW -->
                    <div id="jsci-order-form-view" class="jsci-view"></div>

                </div>
            `;

            // NEW ORDER
            document.getElementById('jsci-new-order')
                ?.addEventListener('click', () => {

                    openOrderForm();

                });

            document.getElementById('jsci-voided-orders-btn')
                ?.addEventListener('click', () => {

                    jsciShowVoidedOrders = true;
                    jsciOrdersPage = 1;
                    renderOrders();

                });

            document.getElementById('jsci-voided-back-btn')
                ?.addEventListener('click', () => {

                    jsciShowVoidedOrders = false;
                    jsciOrdersPage = 1;
                    renderOrders();

                });

            document.getElementById('jsci-order-status-filter')
                ?.addEventListener('change', event => {

                    jsciOrderStatusFilter = event.target.value;
                    jsciOrdersPage = 1;
                    renderOrders();

                });

            document.getElementById('jsci-order-search-form')
                ?.addEventListener('submit', event => {

                    event.preventDefault();
                    jsciOrderSearch = document.getElementById('jsci-order-search')?.value.trim() || '';
                    jsciOrdersPage = 1;
                    renderOrders();

                });

            document.getElementById('jsci-order-search-clear')
                ?.addEventListener('click', () => {

                    jsciOrderSearch = '';
                    jsciOrdersPage = 1;
                    renderOrders();

                });

            document.querySelectorAll('.jsci-orders-page-btn')
                .forEach(button => {

                    button.addEventListener('click', function () {

                        const page = parseInt( this.dataset.page, 10 );
                        if ( Number.isFinite( page ) && page > 0 && page !== jsciOrdersPage ) {
                            jsciOrdersPage = page;
                            renderOrders();
                        }

                    });

                });

            // EDIT ORDER
            document.querySelectorAll('.edit-order-btn')
                .forEach(button => {

                    button.addEventListener('click', async function () {

                        try {
                            const includeDeleted = this.dataset.voided === '1';
                            const order = await api(
                                '/orders/' + this.dataset.id + ( includeDeleted ? '?include_deleted=1' : '' )
                            );
                            openOrderForm(order, includeDeleted ? { readonly: true } : {});
                        } catch ( err ) {
                            alert( err.message );
                        }

                    });

                });

            // OPEN FRONTEND ORDER PAGE
            document.querySelectorAll('.jsci-open-order-entry-btn')
                .forEach(button => {

                    button.addEventListener('click', function () {

                        const url = this.dataset.url;
                        const orderId = this.dataset.orderId;
                        const action = this.dataset.action;

                        if ( url && orderId && action ) {
                            const target = new URL(url, window.location.origin);
                            target.searchParams.set('jsci_order_id', orderId);
                            target.searchParams.set('jsci_action', action);
                            window.open(target.toString(), '_blank', 'noopener,noreferrer');
                            return;
                        }

                        if ( url ) {
                            window.open(url, '_blank', 'noopener,noreferrer');
                        }

                    });

                });

            // VOID ORDER
            document.querySelectorAll('.jsci-void-order-btn')
                .forEach(button => {

                    button.addEventListener('click', async function () {

                        const url = this.dataset.endpoint;
                        const id  = this.dataset.id;

                        if ( ! url ) {
                            return;
                        }

                        const password = await requestPasswordConfirmation(
                            'Void order',
                            'Re-enter your password to confirm voiding this order.'
                        );

                        if ( ! password ) {
                            return;
                        }

                        try {
                            await api(`/orders/${id}`, {
                                method: 'DELETE',
                                body: { password },
                            });

                            renderOrders();
                        } catch ( err ) {
                            alert( err.message );
                        }
                    });
                });

        } catch (err) {

            $app.innerHTML = notice(err.message, 'error');

        }
    }

    function openOrderForm(existing = null, options = {}) {

        const listView = document.getElementById('jsci-orders-list-view');

        const formView = document.getElementById('jsci-order-form-view');

        // Animate list out
        listView.classList.remove('jsci-view--active');

        listView.classList.add('jsci-view--leaving');

        setTimeout(() => {

            listView.style.display = 'none';

            listView.classList.remove('jsci-view--leaving');

            // Show form
            formView.style.display = 'block';

            requestAnimationFrame(() => {

                formView.classList.add('jsci-view--active');

            });

            showOrderForm(existing, options);

        }, 220);
    }

    async function showOrderForm( existing = null, options = {} ) {

        const wrap = document.getElementById('jsci-order-form-view');
        const isVoided = !!existing?.deleted_at;
        const isReadOnly = !!options.readonly || isVoided;
        const title = existing
            ? `${ isReadOnly ? 'View' : 'Edit' } order #${ existing.id }`
            : 'New order';

        const departments = await api('/departments');

        wrap.innerHTML = `
            <div class="jsci-card" style="
                margin-top:24px;
                background:#ffffff;
                border:1px solid #e5e7eb;
                border-radius:24px;
                overflow:hidden;
                box-shadow:0 12px 40px rgba(15,23,42,.08);
            ">

                <div style="
                    background:linear-gradient(135deg, #3da0a1 1%, #df0707 112%);
                    padding:32px;
                    color:#fff;
            border-radius: 20px;
                ">

                    <div style="
                        display:flex;
                        align-items:center;
                        justify-content:space-between;
                        gap:20px;
                        flex-wrap:wrap;
                    ">

                        <div>
                            <h2 style="
                                margin:0;
                                font-size:32px;
                                font-weight:800;
                                letter-spacing:-1px;
                                color: #fff;
                            ">
                                ${ title }
                            </h2>

                            <p style="
                                margin:10px 0 0;
                                color:rgba(255,255,255,.75);
                                font-size:14px;
                                line-height:1.6;
                            ">
                                Production planning, quantity management & department scheduling
                            </p>

                            ${
                                isReadOnly
                                    ? `
                                        <div style="
                                            margin-top:14px;
                                            display:inline-flex;
                                            align-items:center;
                                            gap:8px;
                                            padding:10px 14px;
                                            border-radius:999px;
                                            background:rgba(255,255,255,.14);
                                            color:#fff;
                                            font-size:13px;
                                            font-weight:700;
                                        ">
                                            Voided order. This record can only be viewed.
                                        </div>
                                    `
                                    : ''
                            }
                        </div>

                        <button class="jsci-btn jsci-btn--secondary"
                                id="jsci-cancel-order"
                                style="
                                    height:50px;
                                    padding:0 22px;
                                    border:1px solid #cbd5e1;
                                    border-radius:14px;
                                    background:#fff;
                                    color:#475569;
                                    font-size:14px;
                                    font-weight:700;
                                    cursor:pointer;
                                ">
                            Cancel
                        </button>

                    </div>

                </div>

                <div style="padding:32px">

                    <div class="jsci-form-row" style="
                        display:grid;
                        grid-template-columns:repeat(auto-fit,minmax(240px,1fr));
                        gap:20px;
                        margin-bottom:28px;
                        border-bottom: 1px solid #528c8d;
                        padding: 0 0 29px 0;
                    ">

                        <div class="jsci-field" style="
                            background:#f8fafc;
                            border:1px solid #e2e8f0;
                            border-radius:18px;
                            padding:18px;
                        ">
                            <label style="
                                display:block;
                                margin-bottom:10px;
                                font-size:12px;
                                font-weight:700;
                                color:#64748b;
                                text-transform:uppercase;
                                letter-spacing:.8px;
                            ">
                                Order number *
                            </label>

                            <input id="f-order-number"
                                type="text"
                                value="${ existing?.order_number || '' }"
                                style="
                                    width:100%;
                                    height:48px;
                                    padding:0 14px;
                                    border:1px solid #cbd5e1;
                                    border-radius:12px;
                                    background:#fff;
                                    font-size:15px;
                                    font-weight:600;
                                    color:#0f172a;
                                    outline:none;
                                ">
                        </div>

                        <div class="jsci-field" style="
                            background:#f8fafc;
                            border:1px solid #e2e8f0;
                            border-radius:18px;
                            padding:18px;
                        ">
                            <label style="
                                display:block;
                                margin-bottom:10px;
                                font-size:12px;
                                font-weight:700;
                                color:#64748b;
                                text-transform:uppercase;
                                letter-spacing:.8px;
                            ">
                                Reference number
                            </label>

                            <input id="f-reference-number"
                                type="text"
                                value="${ existing?.reference_number || '' }"
                                style="
                                    width:100%;
                                    height:48px;
                                    padding:0 14px;
                                    border:1px solid #cbd5e1;
                                    border-radius:12px;
                                    background:#fff;
                                    font-size:15px;
                                    font-weight:600;
                                    color:#0f172a;
                                    outline:none;
                                ">
                        </div>

                        <div class="jsci-field" style="
                            background:#f8fafc;
                            border:1px solid #e2e8f0;
                            border-radius:18px;
                            padding:18px;
                        ">
                            <label style="
                                display:block;
                                margin-bottom:10px;
                                font-size:12px;
                                font-weight:700;
                                color:#64748b;
                                text-transform:uppercase;
                                letter-spacing:.8px;
                            ">
                                Buyer name
                            </label>

                            <input id="f-buyer-name"
                                type="text"
                                value="${ existing?.buyer_name || '' }"
                                style="
                                    width:100%;
                                    height:48px;
                                    padding:0 14px;
                                    border:1px solid #cbd5e1;
                                    border-radius:12px;
                                    background:#fff;
                                    font-size:15px;
                                    font-weight:600;
                                    color:#0f172a;
                                    outline:none;
                                ">
                        </div>

                        <div class="jsci-field" style="
                            background:#f8fafc;
                            border:1px solid #e2e8f0;
                            border-radius:18px;
                            padding:18px;
                        ">
                            <label style="
                                display:block;
                                margin-bottom:10px;
                                font-size:12px;
                                font-weight:700;
                                color:#64748b;
                                text-transform:uppercase;
                                letter-spacing:.8px;
                            ">
                                Shipment deadline
                            </label>

                            <input id="f-deadline"
                                type="date"
                                value="${ existing?.shipment_deadline || '' }"
                                style="
                                    width:100%;
                                    height:48px;
                                    padding:0 14px;
                                    border:1px solid #cbd5e1;
                                    border-radius:12px;
                                    background:#fff;
                                    font-size:15px;
                                    font-weight:600;
                                    color:#0f172a;
                                    outline:none;
                                ">
                        </div>

                        <div class="jsci-field" style="
                            background:#f8fafc;
                            border:1px solid #e2e8f0;
                            border-radius:18px;
                            padding:18px;
                        ">
                            <label style="
                                display:block;
                                margin-bottom:10px;
                                font-size:12px;
                                font-weight:700;
                                color:#64748b;
                                text-transform:uppercase;
                                letter-spacing:.8px;
                            ">
                                Fabric qty (kg)
                            </label>

                            <input id="f-fabric"
                                type="number"
                                step="0.001"
                                value="${ existing?.required_fabric_qty || '' }"
                                style="
                                    width:100%;
                                    height:48px;
                                    padding:0 14px;
                                    border:1px solid #cbd5e1;
                                    border-radius:12px;
                                    background:#fff;
                                    font-size:15px;
                                    font-weight:600;
                                    color:#0f172a;
                                    outline:none;
                                ">
                        </div>

                        <div class="jsci-field" style="
                            background:#f8fafc;
                            border:1px solid #e2e8f0;
                            border-radius:18px;
                            padding:18px;
                        ">
                            <label style="
                                display:block;
                                margin-bottom:10px;
                                font-size:12px;
                                font-weight:700;
                                color:#64748b;
                                text-transform:uppercase;
                                letter-spacing:.8px;
                            ">
                                Yarn qty (kg)
                            </label>

                            <input id="f-yarn"
                                type="number"
                                step="0.001"
                                value="${ existing?.required_yarn_qty || '' }"
                                style="
                                    width:100%;
                                    height:48px;
                                    padding:0 14px;
                                    border:1px solid #cbd5e1;
                                    border-radius:12px;
                                    background:#fff;
                                    font-size:15px;
                                    font-weight:600;
                                    color:#0f172a;
                                    outline:none;
                                ">
                        </div>

                    </div>

                    

                    <div style="
                        display:flex;
                        align-items:center;
                        justify-content:space-between;
                        margin-bottom:18px;
                        gap:16px;
                        flex-wrap:wrap;
                    ">

                        <div>
                            <h3 style="
                                margin:0;
                                font-size:24px;
                                font-weight:800;
                                color:#0f172a;
                            ">
                                Sizes
                            </h3>

                            <p style="
                                margin:6px 0 0;
                                color:#64748b;
                                font-size:13px;
                            ">
                                Manage garment size breakdown
                            </p>
                        </div>

                        <button class="jsci-btn jsci-btn--secondary"
                                id="jsci-add-size"
                                style="
                                    border:none;
                                    background:#0f172a;
                                    color:#fff;
                                    padding:12px 18px;
                                    border-radius:14px;
                                    font-size:13px;
                                    font-weight:700;
                                    cursor:pointer;
                                    box-shadow:0 6px 18px rgba(15,23,42,.18);
                                ">
                            + Add size
                        </button>

                    </div>

                    <!-- SIZE ROWS -->
                    <div id="jsci-sizes-rows" style="
                        margin-bottom:34px;
                        display:flex;
                        flex-wrap:wrap;
                        gap:16px;
                        border-bottom: 1px solid #528c8d;
                        padding: 0 0 29px 0;
                    ">

                        ${( existing?.sizes || [] )
                            .map( ( s, i ) => `
                                <div>
                                    ${sizeRow( i, s )}
                                </div>
                            ` )
                            .join('')}

                    </div>

                    <h3 style="
                        margin:0 0 22px;
                        font-size:24px;
                        font-weight:800;
                        color:#0f172a;
                    ">
                        Department Deadlines
                    </h3>

                    <div id="jsci-department-deadlines">

                        <div class="jsci-department-deadline-row jsci-form-row" style="
                            display:grid;
                            gap:18px;
                            border-bottom: 1px solid #528c8d;
                            margin-top: 28px;
                            padding: 0 0 29px 0;
                        ">

                            ${departments.map(dept => {

                                const existingDeadline =
                                    (existing?.department_deadlines || [])
                                    .find(d => parseInt(d.department_id,10) === parseInt(dept.id,10));

                                return `

                                    <div class="jsci-field"
                                         style="
                                            display:flex;
                                            align-items:center;
                                            justify-content:space-between;
                                            gap:20px;
                                            flex-wrap:wrap;
                                            padding:22px;
                                            background:#f8fafc;
                                            border:1px solid #e2e8f0;
                                            border-radius:20px;
                                         ">

                                        <input class="jsci-dept-id"
                                               type="hidden"
                                               value="${dept.id}">

                                        <div>
                                            <label style="
                                                display:block;
                                                margin-bottom:10px;
                                                font-size:14px;
                                                font-weight:800;
                                                color:#0f172a;
                                            ">
                                                ${dept.name}
                                            </label>

                                            <input class="jsci-deadline-date"
                                                   type="date"
                                                   value="${existingDeadline?.deadline_date || ''}"
                                                   style="
                                                        width:195px;
                                                        height:48px;
                                                        padding:0 14px;
                                                        border:1px solid #cbd5e1;
                                                        border-radius:12px;
                                                        background:#fff;
                                                        font-size:14px;
                                                        color:#0f172a;
                                                        outline:none;
                                                   ">
                                        </div>

                                        <div style="
                                            display: flex;
                                                gap: 9px;
                                                flex-wrap: wrap;
                                                height: 33px;
                                                align-items: center;
                                                align-content: center;
                                        ">

                                            <label style="
                                                    display: flex;
                                                        align-items: center;
                                                        gap: 5px;
                                                        font-weight: 700;
                                                        color: #334155;
                                                        cursor: pointer;
                                                        margin: 0;
                                                ">

                                                <input class="jsci-add-extra"
                                                       type="checkbox"
                                                       style="
                                                            width:18px;
                                                            height:18px;
                                                            cursor:pointer;
                                                       "
                                                       ${ parseFloat( existingDeadline?.extra_pct || 0 ) > 0 ? 'checked' : '' }>

                                                Add Extra

                                            </label>

                                            <div style="
                                                    display:flex;
                                                    align-items:center;
                                                    position:relative;
                                                ">

                                                <input class="jsci-extra-pct"
                                                       type="number"
                                                       min="0"
                                                       step="0.01"
                                                       placeholder="0.00"
                                                       style="
                                                            width: 90px;
                                                            height: 29px;
                                                            padding: 0 21px 0 11px;
                                                            border: 1px solid #cbd5e1;
                                                            border-radius: 12px;
                                                            background: #fff;
                                                            font-size: 14px;
                                                            outline: none;
                                                            ${ parseFloat( existingDeadline?.extra_pct || 0 ) > 0 ? '' : 'display:none' }
                                                       "
                                                       value="${ existingDeadline?.extra_pct || '' }">
                                    <span style="
                                                position:absolute;
                                                right:9px;
                                                font-size:12px;
                                                font-weight:700;
                                                color:#64748b;
                                                pointer-events:none;
                                    ${ parseFloat( existingDeadline?.extra_pct || 0 ) > 0 ? '' : 'display:none' }
                                            "
                                            value="${ existingDeadline?.extra_pct || '' }">%</span>

                                            </div>

                                        </div>

                                    </div>

                                `;

                            }).join('')}

                        </div>

                                <div class="jsci-field" style="
                                    margin-bottom:30px;
                                    background:#f8fafc;
                                    border:1px solid #e2e8f0;
                                    border-radius:20px;
                                    padding:22px;
                                ">

                                    <label style="
                                        display:block;
                                        margin-bottom:12px;
                                        font-size:12px;
                                        font-weight:700;
                                        color:#64748b;
                                        text-transform:uppercase;
                                        letter-spacing:.8px;
                                    ">
                                        Notes
                                    </label>
                                    <textarea id="f-notes"
                                        rows="4"
                                        style="
                                            width:100%;
                                            border:1px solid #cbd5e1;
                                            border-radius:14px;
                                            background:#fff;
                                            padding:16px;
                                            font-size:14px;
                                            line-height:1.7;
                                            color:#0f172a;
                                            resize:vertical;
                                            outline:none;
                                        ">${ existing?.notes || '' }</textarea>
                                </div>
                                

                    </div>

                    <div style="
                        display:flex;
                        justify-content:flex-end;
                        gap:14px;
                        margin-top:36px;
                        flex-wrap:wrap;
                    ">

                        <button class="jsci-btn jsci-btn--secondary"
                                id="jsci-cancel-order"
                                style="
                                    height:50px;
                                    padding:0 22px;
                                    border:1px solid #cbd5e1;
                                    border-radius:14px;
                                    background:#fff;
                                    color:#475569;
                                    font-size:14px;
                                    font-weight:700;
                                    cursor:pointer;
                                ">
                            Cancel
                        </button>

                        <button class="jsci-btn jsci-btn--primary"
                                id="jsci-save-order"
                                style="
                                    height:50px;
                                    padding:0 26px;
                                    border:none;
                                    border-radius:14px;
                                    background:linear-gradient(135deg,#2563eb,#1d4ed8);
                                    color:#fff;
                                    font-size:14px;
                                    font-weight:700;
                                    cursor:pointer;
                                    box-shadow:0 10px 24px rgba(37,99,235,.24);
                                ">
                            Save order
                        </button>

                    </div>

                    <div id="jsci-form-notice" style="
                        margin-top:20px;
                    "></div>

                </div>

            </div>
        `;

        let sizeIdx = existing?.sizes?.length || 0;

        document.querySelectorAll( '.jsci-add-extra' )
        .forEach( checkbox => {
            checkbox.addEventListener( 'change', () => {
                const input = checkbox
                    .closest( '.jsci-field' )
                    .querySelector( '.jsci-extra-pct' );

                input.style.display = checkbox.checked ? '' : 'none';

                if ( ! checkbox.checked ) {
                    input.value = '';
                }
            } );
        } );

        document.getElementById( 'jsci-add-size' )
        .addEventListener( 'click', () => {

            document.getElementById( 'jsci-sizes-rows' )
            .insertAdjacentHTML(
                'beforeend',
                sizeRow( sizeIdx++ )
            );

        } );

        document.getElementById('jsci-cancel-order')
            ?.addEventListener('click', () => {

                const listView = document.getElementById('jsci-orders-list-view');

                const formView = document.getElementById('jsci-order-form-view');

                // Animate form out
                formView.classList.remove('jsci-view--active');

                formView.classList.add('jsci-view--leaving');

                setTimeout(() => {

                    formView.style.display = 'none';

                    formView.classList.remove('jsci-view--leaving');

                    // Show list again
                    listView.style.display = 'block';

                    requestAnimationFrame(() => {

                        listView.classList.add('jsci-view--active');

                    });

                }, 220);

            });

        document.getElementById( 'jsci-save-order' )
        .addEventListener( 'click', async () => {

            const btn = document.getElementById( 'jsci-save-order' );

            btn.disabled = true;
            btn.textContent = 'Saving…';

            const sizes = [
                ...document.querySelectorAll( '.jsci-size-row' )
            ].map( row => ({
                size_label: row.querySelector( '.jsci-size-label' ).value.trim(),
                required_qty: parseInt(
                    row.querySelector( '.jsci-size-qty' ).value,
                    10
                ) || 0,
            }) ).filter( s => s.size_label );

            const department_deadlines = [
                ...document.querySelectorAll('.jsci-department-deadline-row')
            ].map(row => ({

                department_id: parseInt(
                    row.querySelector('.jsci-dept-id').value,
                    10
                ),

                deadline_date: row
                    .querySelector('.jsci-deadline-date')
                    .value,

                add_extra: row
                    .querySelector('.jsci-add-extra')
                    .checked,

                extra_pct: parseFloat(
                    row.querySelector('.jsci-extra-pct').value
                ) || 0

            })).filter(row => row.deadline_date || row.add_extra);

            const payload = {

                order_number:
                    document.getElementById( 'f-order-number' ).value.trim(),

                reference_number:
                    document.getElementById( 'f-reference-number' ).value.trim(),

                buyer_name:
                    document.getElementById( 'f-buyer-name' ).value.trim(),

                shipment_deadline:
                    document.getElementById( 'f-deadline' ).value || null,

                required_fabric_qty:
                    parseFloat(
                        document.getElementById( 'f-fabric' ).value
                    ) || 0,

                required_yarn_qty:
                    parseFloat(
                        document.getElementById( 'f-yarn' ).value
                    ) || 0,

                notes:
                    document.getElementById( 'f-notes' ).value.trim(),

                sizes,

                department_deadlines,

            };

            try {

                const method = existing ? 'PUT' : 'POST';

                const path = existing
                    ? `/orders/${ existing.id }`
                    : '/orders';

                await api( path, {
                    method,
                    body: payload
                } );

                document.getElementById( 'jsci-form-notice' ).innerHTML =
                    notice( 'Order saved successfully.', 'success' );

                setTimeout( () => renderOrders(), 1200 );

            } catch ( err ) {

                document.getElementById( 'jsci-form-notice' ).innerHTML =
                    notice( err.message, 'error' );

                btn.disabled = false;
                btn.textContent = 'Save order';

            }

        } );

        if ( isReadOnly ) {
            const warning = document.getElementById('jsci-form-notice');
            if (warning) {
                warning.innerHTML = notice(
                    'Voided orders cannot be edited or unvoided.',
                    'warning'
                );
            }

            const saveBtn = document.getElementById('jsci-save-order');
            if (saveBtn) {
                saveBtn.style.display = 'none';
            }

            wrap.querySelectorAll('input, select, textarea, button')
                .forEach(control => {
                    if (control.id === 'jsci-cancel-order') {
                        return;
                    }

                    if (control.id === 'jsci-save-order') {
                        return;
                    }

                    if (control.id === 'jsci-add-size') {
                        control.disabled = true;
                        return;
                    }

                    control.disabled = true;
                });
        }
    }

    function sizeRow( idx, existing = null ) {
        return `<div class="jsci-size-row jsci-form-row" style="
                                    display: flex;
                                        gap: 12px;
                                        background: #ffffff;
                                        border: 1px solid #e2e8f0;
                                        border-radius: 18px;
                                        padding: 18px 23px 23px 23px;
                                        width: auto;
                                        box-shadow: 0 4px 10px rgba(15, 23, 42, .04);
                                        flex-direction: column;
                                ">
            <div class="jsci-field">
                <label>Size Name</label>
                <input class="jsci-size-label" type="text" placeholder="e.g. M" value="${ existing?.size_label || '' }">
            </div>
            <div class="jsci-field">
                <label>Required qty.</label>
                <input class="jsci-size-qty" type="number" min="0" value="${ existing?.required_qty || '' }">
            </div>
        </div>`;
    }

    function departmentDeadlineRow(idx, existing = null) {

        return `
            <div class="jsci-department-deadline-row jsci-form-row"
                 style="max-width:700px">

                <div class="jsci-field">
                    <label>Department ID</label>
                    <input class="jsci-dept-id"
                           type="number"
                           min="1"
                           value="${existing?.department_id || ''}">
                </div>

                <div class="jsci-field">
                    <label>Deadline Date</label>
                    <input class="jsci-deadline-date"
                           type="date"
                           value="${existing?.deadline_date || ''}">
                </div>

            </div>
        `;
    }

    // ── Transactions ──────────────────────────────────────────────────────────

    async function renderTransactions() {
        $app.innerHTML = loader();

        try {
            const departments = await api( '/departments' );
            let txPath = `/transactions?per_page=25&page=${ jsciTransactionsPage }`;
            let pageTitle = 'Transactions';
            const txQuery = new URLSearchParams( {
                per_page: '25',
                page: String( jsciTransactionsPage ),
            } );

            if ( jsciTransactionsView === 'voided' ) {
                txQuery.set( 'status', 'voided' );
                txQuery.set( 'include_voided', '1' );
                txQuery.set( 'exclude_declined', '1' );
                pageTitle = 'Voided Transactions';
            } else if ( jsciTransactionsView === 'rejected_voided' ) {
                txQuery.set( 'status', 'voided' );
                txQuery.set( 'include_voided', '1' );
                txQuery.set( 'declined_only', '1' );
                pageTitle = 'Rejected Transactions';
            }
            if ( jsciTransactionTypeFilter ) {
                txQuery.set( 'tx_type', jsciTransactionTypeFilter );
            }
            if ( jsciTransactionDepartmentFilter ) {
                txQuery.set( 'department_id', jsciTransactionDepartmentFilter );
            }
            if ( jsciTransactionCreatedDateFilter ) {
                txQuery.set( 'created_date', jsciTransactionCreatedDateFilter );
            }
            txPath = `/transactions?${ txQuery.toString() }`;
            const response = await api( txPath, { returnMeta: true } );
            const txs = response.data || [];
            const totalPages = Math.max( response.meta?.totalPages || 1, 1 );
            const currentPage = Math.min( jsciTransactionsPage, totalPages );

            if ( currentPage !== jsciTransactionsPage ) {
                jsciTransactionsPage = currentPage;
                return renderTransactions();
            }

            $app.innerHTML = `
                <div class="jsci-prm-wrap">
                    <div style="display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;margin-bottom:16px">
                        <a href="/home/"style="background: #C6F5F5;padding: 8px 35px;color: #009688;text-decoration: none;font-size: 14px;font-weight: bold;border: 1px solid #01C4C6;">
                        ↩ Back To Site
                        </a>
                        <h1 style="margin:0">${ pageTitle }</h1>
                        <div style="display:flex;gap:8px;flex-wrap:wrap">
                            <button
                                type="button"
                                class="jsci-btn ${ jsciTransactionsView === 'active' ? 'jsci-btn--primary' : 'jsci-btn--secondary' }"
                                id="jsci-active-transactions-btn">
                                Active transactions
                            </button>
                            <button
                                type="button"
                                class="jsci-btn ${ jsciTransactionsView === 'rejected_voided' ? 'jsci-btn--primary' : 'jsci-btn--secondary' }"
                                id="jsci-rejected-voided-transactions-btn">
                                Rejected transactions
                            </button>
                            <button
                                type="button"
                                class="jsci-btn ${ jsciTransactionsView === 'voided' ? 'jsci-btn--primary' : 'jsci-btn--secondary' }"
                                id="jsci-voided-transactions-btn">
                                Voided transaction
                            </button>
                        </div>
                    </div>
                    <div class="jsci-card jsci-transaction-filters">
                        <div class="jsci-transaction-filters__header">
                            <div>
                                <h2 class="jsci-transaction-filters__title">Filter transactions</h2>
                                <p class="jsci-transaction-filters__subtitle">Narrow the list by type, department, or created date.</p>
                            </div>
                        </div>
                        <div class="jsci-transaction-filters__grid">
                            <div class="jsci-field jsci-transaction-filters__field">
                                <label>Type</label>
                                <select id="jsci-transaction-type-filter">
                                    <option value="">All types</option>
                                    ${ [
                                        [ 'receive', 'Receive' ],
                                        [ 'manual_receive', 'Manual Receive' ],
                                        [ 'produce', 'Produce' ],
                                        [ 'send', 'Send' ],
                                        [ 'send_outside_factory', 'Send Outside Factory' ],
                                        [ 'reject', 'Reject' ],
                                        [ 'return', 'Return' ],
                                    ].map( ( [ value, label ] ) => `
                                        <option value="${ value }" ${ jsciTransactionTypeFilter === value ? 'selected' : '' }>${ label }</option>
                                    ` ).join( '' ) }
                                </select>
                            </div>
                            <div class="jsci-field jsci-transaction-filters__field">
                                <label>Department</label>
                                <select id="jsci-transaction-department-filter">
                                    <option value="">All departments</option>
                                    ${ departments.map( dept => `
                                        <option value="${ esc( dept.id ) }" ${ String( jsciTransactionDepartmentFilter ) === String( dept.id ) ? 'selected' : '' }>
                                            ${ esc( dept.name ) }
                                        </option>
                                    ` ).join( '' ) }
                                </select>
                            </div>
                            <div class="jsci-field jsci-transaction-filters__field">
                                <label>Created date</label>
                                <input id="jsci-transaction-created-date-filter" type="date" value="${ esc( jsciTransactionCreatedDateFilter ) }">
                            </div>
                            <div class="jsci-transaction-filters__actions">
                                <button type="button" class="jsci-btn jsci-btn--primary" id="jsci-transaction-apply-filters">
                                    Apply filters
                                </button>
                                <button type="button" class="jsci-btn jsci-btn--secondary" id="jsci-transaction-clear-filters">
                                    Clear filters
                                </button>
                            </div>
                        </div>
                    </div>
                    ${ txTable( txs ) }
                    ${ totalPages > 1 ? renderTransactionPagination( currentPage, totalPages ) : '' }
                </div>
            `;
        } catch ( err ) {
            $app.innerHTML = notice( err.message, 'error' );
        }
    }
    document.addEventListener('click', async (e) => {

        if (e.target.classList.contains('jsci-view-tx')) {

            const id = e.target.dataset.id;
            const includeVoided = e.target.dataset.includeVoided === '1';
            const popup = document.getElementById( 'jsci-transaction-popup' );

            if ( popup ) {
                popup.remove();
            }

            document.body.insertAdjacentHTML( 'beforeend', `
                <div id="jsci-transaction-popup"
                     class="jsci-transaction-overlay">
                    <div class="jsci-card jsci-transaction-modal">
                        <div id="jsci-transaction-popup-content">${ loader() }</div>
                    </div>
                </div>
            ` );

            try {
                const tx = await api(`/transactions/${id}${ includeVoided ? '?include_voided=1' : '' }`);
                document.getElementById( 'jsci-transaction-popup-content' ).innerHTML = renderTransactionPopup( tx );
            } catch ( err ) {
                document.getElementById( 'jsci-transaction-popup-content' ).innerHTML = notice( err.message, 'error' );
            }

        }

        if ( e.target.id === 'jsci-active-transactions-btn' ) {
            jsciTransactionsView = 'active';
            jsciTransactionsPage = 1;
            renderTransactions();
        }

        if ( e.target.id === 'jsci-voided-transactions-btn' ) {
            jsciTransactionsView = 'voided';
            jsciTransactionsPage = 1;
            renderTransactions();
        }

        if ( e.target.id === 'jsci-rejected-voided-transactions-btn' ) {
            jsciTransactionsView = 'rejected_voided';
            jsciTransactionsPage = 1;
            renderTransactions();
        }

        if ( e.target.id === 'jsci-transaction-apply-filters' ) {
            jsciTransactionTypeFilter = document.getElementById( 'jsci-transaction-type-filter' )?.value || '';
            jsciTransactionDepartmentFilter = document.getElementById( 'jsci-transaction-department-filter' )?.value || '';
            jsciTransactionCreatedDateFilter = document.getElementById( 'jsci-transaction-created-date-filter' )?.value || '';
            jsciTransactionsPage = 1;
            renderTransactions();
        }

        if ( e.target.id === 'jsci-transaction-clear-filters' ) {
            jsciTransactionTypeFilter = '';
            jsciTransactionDepartmentFilter = '';
            jsciTransactionCreatedDateFilter = '';
            jsciTransactionsPage = 1;
            renderTransactions();
        }

        if ( e.target.classList.contains( 'jsci-transactions-page-btn' ) ) {
            const page = parseInt( e.target.dataset.page || '1', 10 );

            if ( Number.isFinite( page ) && page > 0 && page !== jsciTransactionsPage ) {
                jsciTransactionsPage = page;
                renderTransactions();
            }
        }

        if (
            e.target.closest( '.jsci-close-tx-popup' )
            || e.target.id === 'jsci-transaction-popup'
        ) {
            document.getElementById( 'jsci-transaction-popup' )?.remove();
        }

        if ( e.target.closest( '.jsci-print-tx-popup' ) ) {
            window.print();
        }

        // EDIT
        if (e.target.classList.contains('jsci-edit-tx')) {

            const id = e.target.dataset.id;

            const tx = await api(`/transactions/${id}`);

            showTransactionForm(tx);

        }

        // VOID
        if (e.target.classList.contains('jsci-delete-tx')) {

            const id = e.target.dataset.id;

            if (!confirm('Void this transaction?')) {
                return;
            }

            await api(`/transactions/${id}/void`, {
                method: 'POST'
            });

            renderTransactions();

        }

    });
    // ── Departments ───────────────────────────────────────────────────────────

    async function showTransactionForm( existing ) {
        const wrap = document.querySelector( '.jsci-prm-wrap' );

        if ( ! wrap ) {
            return;
        }

        wrap.innerHTML = loader();

        try {
            const [ orders, departments ] = await Promise.all( [
                api( '/orders?per_page=100' ),
                api( '/departments' ),
            ] );

            const itemMap = new Map(
                ( existing.items || [] ).map( item => [
                    String( item.order_size_id ),
                    item
                ] )
            );

            const externalOrganizationsData = await api( '/external-organizations' );
            const selectedOrderId = String( existing.order_id || '' );
            const isKgEntry = existing.entry_mode === 'kg';
            const isCuttingEntry = existing.entry_mode === 'cutting';
            const entryMode = isKgEntry ? 'kg' : ( isCuttingEntry ? 'cutting' : 'size' );
            const selectedOrder = orders.find( order => String( order.id ) === selectedOrderId );
            const selectedBuyerName = String( selectedOrder?.buyer_name || '' );
            const currentExternalOrganizationName = String( existing.external_organization_name || '' );
            const externalOrganizationOptions = Array.isArray( externalOrganizationsData?.active )
                ? [ ...externalOrganizationsData.active ]
                : [];
            const isShipmentSendOutside = existing.tx_type === 'send_outside_factory' && existing.send_outside_purpose === 'shipment';

            if (
                currentExternalOrganizationName
                && ! isShipmentSendOutside
                && ! externalOrganizationOptions.includes( currentExternalOrganizationName )
            ) {
                externalOrganizationOptions.unshift( currentExternalOrganizationName );
            }

            wrap.innerHTML = `
                <div style="display:flex;align-items:center;justify-content:space-between;gap:16px;margin-bottom:16px">
                    <h1 style="margin:0">Transactions</h1>
                    <button class="jsci-btn jsci-btn--secondary" id="jsci-back-to-transactions">
                        Back to list
                    </button>
                </div>

                <div class="jsci-card">
                    <h2>Edit transaction ${ esc( existing.tx_number ) }</h2>

                    <div class="jsci-form-row">
                        <div class="jsci-field jsci-field--locked">
                            <label>Order *</label>
                            <select id="tx-order-id" disabled>
                                <option value="">Select order</option>
                                ${ orders.map( order => `
                                    <option value="${ esc( order.id ) }"
                                        ${ String( order.id ) === selectedOrderId ? 'selected' : '' }>
                                        #${ esc( order.id ) } - ${ esc( order.order_number ) }
                                    </option>
                                ` ).join( '' ) }
                            </select>
                        </div>

                        <div class="jsci-field jsci-field--locked">
                            <label>Type *</label>
                            <select id="tx-type" disabled>
                                ${ [ 'receive', 'manual_receive', 'produce', 'send', 'send_outside_factory', 'reject', 'return' ].map( type => `
                                    <option value="${ type }" ${ existing.tx_type === type ? 'selected' : '' }>
                                        ${ formatTransactionTypeLabel( type ) }
                                    </option>
                                ` ).join( '' ) }
                            </select>
                        </div>

                        <div class="jsci-field jsci-field--locked">
                            <label>From department</label>
                            <select id="tx-from-dept" disabled>
                                <option value="">None</option>
                                ${ departments.map( dept => `
                                    <option value="${ esc( dept.id ) }"
                                        ${ String( dept.id ) === String( existing.from_dept_id || '' ) ? 'selected' : '' }>
                                        ${ esc( dept.name ) }
                                    </option>
                                ` ).join( '' ) }
                            </select>
                        </div>

                        <div class="jsci-field jsci-field--locked">
                            <label>To department</label>
                            <select id="tx-to-dept" disabled>
                                <option value="">None</option>
                                ${ departments.map( dept => `
                                    <option value="${ esc( dept.id ) }"
                                        ${ String( dept.id ) === String( existing.to_dept_id || '' ) ? 'selected' : '' }>
                                        ${ esc( dept.name ) }
                                    </option>
                                ` ).join( '' ) }
                            </select>
                        </div>

                        <div class="jsci-field jsci-field--locked">
                            <label>Line number</label>
                            <input id="tx-line-number" type="text" value="${ esc( existing.line_number || '' ) }" disabled>
                        </div>
                    </div>

                    ${ [ 'send_outside_factory', 'manual_receive' ].includes( existing.tx_type ) ? `
                        <div class="jsci-form-row">
                            ${ existing.tx_type === 'send_outside_factory' ? `
                                <div class="jsci-field jsci-field--locked">
                                    <label>Send Outside Purpose</label>
                                    <select id="tx-send-outside-purpose" disabled>
                                        <option value="">Select purpose</option>
                                        <option value="embroidery" ${ existing.send_outside_purpose === 'embroidery' ? 'selected' : '' }>Embroidery</option>
                                        <option value="dyeing" ${ existing.send_outside_purpose === 'dyeing' ? 'selected' : '' }>Dyeing</option>
                                        <option value="shipment" ${ existing.send_outside_purpose === 'shipment' ? 'selected' : '' }>SHIPMENT</option>
                                    </select>
                                </div>
                            ` : '' }

                            <div class="jsci-field">
                                <label style="margin-bottom: 7px;">${ isShipmentSendOutside ? 'Buyer Name' : ( existing.tx_type === 'manual_receive' ? 'Receive from External organization name' : 'External organization name' ) }</label>
                                <select id="tx-external-organization-name" style="height: 47px;padding-top: 5px;">
                                    ${ isShipmentSendOutside ? `
                                        <option value="${ esc( selectedBuyerName ) }" selected>
                                            ${ esc( selectedBuyerName || 'Order has no Buyer Name' ) }
                                        </option>
                                    ` : `
                                        <option value="">Select external organization</option>
                                        ${ externalOrganizationOptions.map( name => `
                                            <option value="${ esc( name ) }" ${ name === currentExternalOrganizationName ? 'selected' : '' }>
                                                ${ esc( name ) }
                                            </option>
                                        ` ).join( '' ) }
                                    ` }
                                </select>
                            </div>
                        </div>
                    ` : '' }

                    ${ isCuttingEntry ? `
                        <div class="jsci-form-row" style="max-width:420px">
                            <div class="jsci-field">
                                <label>Amount of fabric used (KG)</label>
                                <input id="tx-fabric-used-kg" type="number" min="0" step="0.001"
                                    value="${ esc( existing.fabric_used_kg || '' ) }">
                            </div>
                        </div>
                    ` : '' }

                    <h3>${ isKgEntry ? 'Fabric KG quantity' : ( isCuttingEntry ? 'Fabric to Piece conversion quantities' : 'Size quantities' ) }</h3>
                    <div id="tx-size-rows"></div>

                    <div class="jsci-field" style="margin-top:16px">
                        <label>Notes</label>
                        <textarea id="tx-notes" rows="3">${ esc( existing.notes || '' ) }</textarea>
                    </div>

                    <div style="display:flex;gap:10px;margin-top:20px">
                        <button class="jsci-btn jsci-btn--primary" id="jsci-save-transaction">
                            Save transaction
                        </button>

                        <button class="jsci-btn jsci-btn--secondary" id="jsci-cancel-transaction">
                            Cancel
                        </button>
                    </div>

                    <div id="jsci-transaction-notice"></div>
                </div>
            `;

            document.getElementById( 'jsci-back-to-transactions' )
                .addEventListener( 'click', () => renderTransactions() );

            const renderSizeRows = () => {
                const orderId = document.getElementById( 'tx-order-id' ).value;
                const order = orders.find( candidate => String( candidate.id ) === String( orderId ) );
                const sizes = order?.sizes || [];
                const kgQuantity = existing.items?.[0]?.quantity ?? '';

                if ( isKgEntry ) {
                    document.getElementById( 'tx-size-rows' ).innerHTML = `
                        <div class="jsci-tx-size-row jsci-form-row" style="max-width:420px">
                            <input class="tx-order-size-id" type="hidden" value="0">
                            <input class="tx-size-label" type="hidden" value="Fabric KG">

                            <div class="jsci-field">
                                <label>Fabric KG target</label>
                                <input type="text" value="${ esc( order?.required_fabric_qty || '' ) }" disabled>
                            </div>

                            <div class="jsci-field">
                                <label>Quantity</label>
                                <input class="tx-quantity" type="number" min="0" step="0.001"
                                    value="${ esc( kgQuantity ) }">
                            </div>
                        </div>
                    `;
                    return;
                }

                document.getElementById( 'tx-size-rows' ).innerHTML = sizes.length
                    ? sizes.map( size => {
                        const existingItem = itemMap.get( String( size.id ) );

                        return `
                            <div class="jsci-tx-size-row jsci-form-row" style="max-width:420px">
                                <input class="tx-order-size-id" type="hidden" value="${ esc( size.id ) }">
                                <input class="tx-size-label" type="hidden" value="${ esc( size.size_label ) }">

                                <div class="jsci-field">
                                    <label>Size</label>
                                    <input type="text" value="${ esc( size.size_label ) }" disabled>
                                </div>

                                <div class="jsci-field">
                                    <label>Quantity</label>
                                    <input class="tx-quantity" type="number" min="0"
                                        value="${ esc( existingItem?.quantity || '' ) }">
                                </div>
                            </div>
                        `;
                    } ).join( '' )
                    : notice( 'The selected order has no sizes.', 'info' );
            };

            document.getElementById( 'tx-order-id' )
                .addEventListener( 'change', renderSizeRows );

            document.getElementById( 'jsci-cancel-transaction' )
                .addEventListener( 'click', () => renderTransactions() );

            document.getElementById( 'jsci-save-transaction' )
                .addEventListener( 'click', async () => {
                    const btn = document.getElementById( 'jsci-save-transaction' );
                    const msg = document.getElementById( 'jsci-transaction-notice' );

                    btn.disabled = true;
                    btn.textContent = 'Saving...';

                    const items = [
                        ...document.querySelectorAll( '.jsci-tx-size-row' )
                    ].map( row => ( {
                        order_size_id: parseInt( row.querySelector( '.tx-order-size-id' ).value, 10 ),
                        size_label: row.querySelector( '.tx-size-label' ).value,
                        quantity: isKgEntry
                            ? parseFloat( row.querySelector( '.tx-quantity' ).value ) || 0
                            : parseInt( row.querySelector( '.tx-quantity' ).value, 10 ) || 0,
                    } ) );

                    const payload = {
                        order_id: parseInt( document.getElementById( 'tx-order-id' ).value, 10 ) || 0,
                        from_dept_id: parseInt( document.getElementById( 'tx-from-dept' ).value, 10 ) || null,
                        to_dept_id: parseInt( document.getElementById( 'tx-to-dept' ).value, 10 ) || null,
                        tx_type: document.getElementById( 'tx-type' ).value,
                        entry_mode: entryMode,
                        line_number: document.getElementById( 'tx-line-number' ).value.trim(),
                        reject_stage: existing.reject_stage || '',
                        notes: document.getElementById( 'tx-notes' ).value.trim(),
                        items,
                    };

                    if ( isCuttingEntry ) {
                        payload.fabric_used_kg = parseFloat( document.getElementById( 'tx-fabric-used-kg' )?.value ) || 0;
                    }

                    if ( [ 'send_outside_factory', 'manual_receive' ].includes( payload.tx_type ) ) {
                        payload.external_organization_name = document.getElementById( 'tx-external-organization-name' )?.value.trim() || '';
                    }

                    if ( payload.tx_type === 'send_outside_factory' ) {
                        payload.send_outside_purpose = document.getElementById( 'tx-send-outside-purpose' )?.value || '';
                    }

                    try {
                        await api( `/transactions/${ existing.id }/update`, {
                            method: 'POST',
                            body: payload,
                        } );

                        msg.innerHTML = notice( 'Transaction saved successfully.', 'success' );
                        setTimeout( () => renderTransactions(), 1000 );
                    } catch ( err ) {
                        msg.innerHTML = notice( err.message, 'error' );
                        btn.disabled = false;
                        btn.textContent = 'Save transaction';
                    }
                } );

            renderSizeRows();
        } catch ( err ) {
            wrap.innerHTML = notice( err.message, 'error' );
        }
    }

    async function renderDepartments() {
        $app.innerHTML = loader();

        try {
            const depts = await api( '/departments' );
            const rows  = depts.map( d => `
                <tr>
                    <td>${ esc( d.id ) }</td>
                    <td>${ esc( d.name ) }</td>
                    <td><code>${ esc( d.tx_prefix ) }</code></td>
                    <td>${ esc( d.workflow_order ) }</td>
                    <td>${ Number( d.is_active ) ? 'Active' : 'Inactive' }</td>
                    <td>${ d.lines?.length ? d.lines.map( ( line ) => esc( line.line_number ) ).join(', ') : '-' }</td>
                    <td>
                        ${
                            d.actions?.edit
                                ? `
                                    <button
                                        type="button"
                                        class="jsci-btn jsci-btn--secondary jsci-edit-department"
                                        data-id="${ esc( d.id ) }">
                                        ${ esc( d.actions.edit.label || 'Edit' ) }
                                    </button>
                                `
                                : ''
                        }
                    </td>
                </tr>
            ` ).join('');

            $app.innerHTML = `
                <div class="jsci-prm-wrap">
                    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:16px">
                        <h1>Departments</h1>
                        <button class="jsci-btn jsci-btn--primary" id="jsci-new-department">
                            + Add Department
                        </button>
                    </div>
                    <table class="jsci-table">
                        <thead><tr>
                            <th>#</th><th>Name</th><th>TX prefix</th><th>Order</th><th>Active</th><th>Lines</th><th>Actions</th>
                        </tr></thead>
                        <tbody>${ rows || '<tr><td colspan="7">No departments yet.</td></tr>' }</tbody>
                    </table>
                    <div id="jsci-department-form-wrap"></div>
                </div>
            `;
            document.getElementById('jsci-new-department')
            ?.addEventListener('click', () => showDepartmentForm( null, depts ));

            document.querySelectorAll('.jsci-edit-department')
                .forEach((button) => {
                    button.addEventListener('click', () => {
                        const department = depts.find(
                            (dept) => Number(dept.id) === Number(button.dataset.id)
                        );

                        if (department) {
                            showDepartmentForm(department, depts);
                        }
                    });
                });
        } catch ( err ) {
            $app.innerHTML = notice( err.message, 'error' );
        }
    }

    function showDepartmentForm( department = null, departments = [] ) {
        const isEditing = Boolean( department );
        const formWrap = document.getElementById('jsci-department-form-wrap');
        const initialLines = Array.isArray( department?.lines ) && department.lines.length
            ? department.lines
            : [];
        const sendToAllDepartments = Boolean( department?.send_to_all_departments ?? true );
        const sendToAllDepartmentsKg = Boolean( department?.send_to_all_departments_kg ?? true );
        const compatibilityTypes = Array.isArray( department?.compatibility_transaction_types )
            ? department.compatibility_transaction_types
            : [ 'receive', 'produce', 'send', 'send_outside_factory', 'reject' ];
        const sendOutsidePurposeOptions = [
            { key: 'embroidery', label: 'Embroidery' },
            { key: 'dyeing', label: 'Dyeing' },
            { key: 'shipment', label: 'SHIPMENT' },
        ];
        const selectedSendOutsidePurposes = Array.isArray( department?.send_outside_purposes )
            ? department.send_outside_purposes.map( purpose => String( purpose ) )
            : sendOutsidePurposeOptions.map( purpose => purpose.key );
        const selectedSendOutsidePurposesKg = Array.isArray( department?.send_outside_purposes_kg )
            ? department.send_outside_purposes_kg.map( purpose => String( purpose ) )
            : sendOutsidePurposeOptions.map( purpose => purpose.key );
        const rejectStageOptions = [
            { key: 'before_production', label: 'Before-Production' },
            { key: 'after_production', label: 'After-Production' },
        ];
        const selectedRejectStages = Array.isArray( department?.reject_stages )
            ? department.reject_stages.map( stage => String( stage ) )
            : rejectStageOptions.map( stage => stage.key );
        const selectedRejectStagesKg = Array.isArray( department?.reject_stages_kg )
            ? department.reject_stages_kg.map( stage => String( stage ) )
            : rejectStageOptions.map( stage => stage.key );
        const behaviorFields = [
            { key: 'manual', label: 'Manual receive entry form' },
            { key: 'production', label: 'Production form' },
            { key: 'fabric_to_piece_conversion', label: 'Fabric to Piece conversion form', sizeOnly: true },
            { key: 'auto_produce_on_receive', label: 'Auto production upon receiving (Production form will be Useless)', direct: true, danger: true },
            { key: 'reject', label: 'Reject form' },
            { key: 'send_outside_factory', label: 'Send Outside Factory' },
            { key: 'send', label: 'Send to another department' },
        ];
        const behaviorCheckboxes = ( suffix = '' ) => behaviorFields.map( field => {
            if ( suffix === 'kg' && field.sizeOnly ) {
                return '';
            }

            const fieldKey = field.key.replaceAll('_', '-');
            const id = field.direct
                ? `dept-${ fieldKey }${ suffix ? '-' + suffix : '' }`
                : `dept-allow-${ fieldKey }-entry${ suffix ? '-' + suffix : '' }`;
            const dbField = field.direct
                ? `${ field.key }${ suffix ? '_' + suffix : '' }`
                : `allow_${ field.key }_entry${ suffix ? '_' + suffix : '' }`;
            const defaultValue = field.direct || suffix === 'kg' ? 0 : 1;

            return `
                <label style="display:flex;align-items:center;gap:8px;margin:8px 0">
                    <input type="checkbox" id="${ id }" ${ Number( department?.[dbField] ?? defaultValue ) ? 'checked' : '' }>
                    <span${ field.danger ? ' style="color:#dc2626;font-weight:600"' : '' }>${ field.label }</span>
                </label>
            `;
        }).join('');
        const sendToIds = sendToAllDepartments
            ? departments
                .filter( dept => !department || Number( dept.id ) !== Number( department.id ) )
                .map( dept => String( dept.id ) )
            : (
                Array.isArray( department?.send_to_department_ids )
                    ? department.send_to_department_ids.map( id => String( id ) )
                    : []
            );
        const sendToIdsKg = sendToAllDepartmentsKg
            ? departments
                .filter( dept => !department || Number( dept.id ) !== Number( department.id ) )
                .map( dept => String( dept.id ) )
            : (
                Array.isArray( department?.send_to_department_ids_kg )
                    ? department.send_to_department_ids_kg.map( id => String( id ) )
                    : []
            );
        const destinationOptions = ( suffix = '', selectedIds = sendToIds ) => departments
            .filter( dept => !department || Number( dept.id ) !== Number( department.id ) )
            .map( dept => `
                <label style="display:flex;align-items:center;gap:8px;margin:6px 0">
                    <input
                        type="checkbox"
                        class="dept-send-destination${ suffix ? '-' + suffix : '' }"
                        value="${ esc( dept.id ) }"
                        ${ selectedIds.includes( String( dept.id ) ) ? 'checked' : '' }>
                    <span>${ esc( dept.name ) }</span>
                </label>
            ` ).join('');
        const sendOutsidePurposeCheckboxes = ( suffix = '', selectedPurposes = selectedSendOutsidePurposes ) => sendOutsidePurposeOptions.map( purpose => `
            <label style="display:flex;align-items:center;gap:8px;margin:6px 0">
                <input
                    type="checkbox"
                    class="dept-send-outside-purpose${ suffix ? '-' + suffix : '' }"
                    value="${ esc( purpose.key ) }"
                    ${ selectedPurposes.includes( purpose.key ) ? 'checked' : '' }>
                <span>${ esc( purpose.label ) }</span>
            </label>
        ` ).join('');
        const rejectStageCheckboxes = ( suffix = '', selectedStages = selectedRejectStages ) => rejectStageOptions.map( stage => `
            <label style="display:flex;align-items:center;gap:8px;margin:6px 0">
                <input
                    type="checkbox"
                    class="dept-reject-stage${ suffix ? '-' + suffix : '' }"
                    value="${ esc( stage.key ) }"
                    ${ selectedStages.includes( stage.key ) ? 'checked' : '' }>
                <span>${ esc( stage.label ) }</span>
            </label>
        ` ).join('');

        if (!formWrap) {
            return;
        }

        formWrap.innerHTML = `
            <div class="jsci-card" style="margin-top:20px" id="jsci-department-form-card">
                <div style="display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap">
                    <h2 style="margin:0">${ isEditing ? 'Edit Department' : 'Add Department' }</h2>
                    <button type="button" class="jsci-btn jsci-btn--secondary" id="cancel-dept-form">
                        Close
                    </button>
                </div>

                <div class="jsci-form-row">
                    <div class="jsci-field">
                        <label>Name</label>
                        <input
                            type="text"
                            id="dept-name"
                            value="${ esc( department?.name || '' ) }">
                    </div>

                    <div class="jsci-field">
                        <label>TX Prefix</label>
                        <input type="text" id="dept-prefix" value="${ esc( department?.tx_prefix || '' ) }">
                    </div>

                    <div class="jsci-field">
                        <label>Workflow Order</label>
                        <input type="number" id="dept-order" value="${ esc( department?.workflow_order ?? 0 ) }">
                    </div>

                    <div class="jsci-field">
                        <label>Active</label>
                        <select id="dept-active">
                            <option value="1" ${ Number( department?.is_active ?? 1 ) ? 'selected' : '' }>Yes</option>
                            <option value="0" ${ !Number( department?.is_active ?? 1 ) ? 'selected' : '' }>No</option>
                        </select>
                    </div>
                </div>

                <div class="jsci-card" style="margin-top:16px;padding:16px">
                    <h3 style="margin:0 0 12px">Department Behavior</h3>
                    ${ behaviorCheckboxes() }
                    <div id="dept-reject-stages-wrap" style="margin-top:12px;padding:12px;border:1px solid #dcdcde;border-radius:8px">
                        <strong>Allowed Reject Stage</strong>
                        <div style="margin-top:8px">
                            ${ rejectStageCheckboxes() }
                        </div>
                    </div>
                    <div id="dept-send-outside-purposes-wrap" style="margin-top:12px;padding:12px;border:1px solid #dcdcde;border-radius:8px">
                        <strong>Allowed Send Outside Purpose</strong>
                        <div style="margin-top:8px">
                            ${ sendOutsidePurposeCheckboxes() }
                        </div>
                    </div>
                    <div id="dept-send-destinations-wrap" style="margin-top:12px;padding:12px;border:1px solid #dcdcde;border-radius:8px">
                        <strong>Allowed Send To Departments</strong>
                        <label style="display:flex;align-items:center;gap:8px;margin:8px 0">
                            <input type="checkbox" id="dept-send-to-all" ${ sendToAllDepartments ? 'checked' : '' }>
                            <span>Allow sending to all departments</span>
                        </label>
                        <div style="margin-top:8px">
                            ${ destinationOptions() || '<span style="color:#64748b">No other departments available.</span>' }
                        </div>
                    </div>
                </div>

                <div class="jsci-card" style="margin-top:16px;padding:16px">
                    <h3 style="margin:0 0 12px">Department Behavior (kg)</h3>
                    ${ behaviorCheckboxes('kg') }
                    <div id="dept-reject-stages-wrap-kg" style="margin-top:12px;padding:12px;border:1px solid #dcdcde;border-radius:8px">
                        <strong>Allowed Reject Stage (kg)</strong>
                        <div style="margin-top:8px">
                            ${ rejectStageCheckboxes('kg', selectedRejectStagesKg) }
                        </div>
                    </div>
                    <div id="dept-send-outside-purposes-wrap-kg" style="margin-top:12px;padding:12px;border:1px solid #dcdcde;border-radius:8px">
                        <strong>Allowed Send Outside Purpose (kg)</strong>
                        <div style="margin-top:8px">
                            ${ sendOutsidePurposeCheckboxes('kg', selectedSendOutsidePurposesKg) }
                        </div>
                    </div>
                    <div id="dept-send-destinations-wrap-kg" style="margin-top:12px;padding:12px;border:1px solid #dcdcde;border-radius:8px">
                        <strong>Allowed Send To Departments (kg)</strong>
                        <label style="display:flex;align-items:center;gap:8px;margin:8px 0">
                            <input type="checkbox" id="dept-send-to-all-kg" ${ sendToAllDepartmentsKg ? 'checked' : '' }>
                            <span>Allow sending to all departments</span>
                        </label>
                        <div style="margin-top:8px">
                            ${ destinationOptions('kg', sendToIdsKg) || '<span style="color:#64748b">No other departments available.</span>' }
                        </div>
                    </div>
                </div>

                <div class="jsci-card" style="margin-top:16px;padding:16px">
                    <h3 style="margin:0 0 12px">Compatibility</h3>
                    <p style="margin:0 0 12px;color:#64748b">
                        If unchecked, that transaction type will not be blocked by stock availability validation for this department.
                    </p>
                    <label style="display:flex;align-items:center;gap:8px;margin:8px 0">
                        <input type="checkbox" class="dept-compatibility-type" value="receive" ${ compatibilityTypes.includes( 'receive' ) ? 'checked' : '' }>
                        <span>Receive</span>
                    </label>
                    <label style="display:flex;align-items:center;gap:8px;margin:8px 0">
                        <input type="checkbox" class="dept-compatibility-type" value="produce" ${ compatibilityTypes.includes( 'produce' ) ? 'checked' : '' }>
                        <span>Production</span>
                    </label>
                    <label style="display:flex;align-items:center;gap:8px;margin:8px 0">
                        <input type="checkbox" class="dept-compatibility-type" value="send" ${ compatibilityTypes.includes( 'send' ) ? 'checked' : '' }>
                        <span>Send</span>
                    </label>
                    <label style="display:flex;align-items:center;gap:8px;margin:8px 0">
                        <input type="checkbox" class="dept-compatibility-type" value="send_outside_factory" ${ compatibilityTypes.includes( 'send_outside_factory' ) ? 'checked' : '' }>
                        <span>Send Outside Factory</span>
                    </label>
                    <label style="display:flex;align-items:center;gap:8px;margin:8px 0">
                        <input type="checkbox" class="dept-compatibility-type" value="reject" ${ compatibilityTypes.includes( 'reject' ) ? 'checked' : '' }>
                        <span>Reject</span>
                    </label>
                </div>

                <div class="jsci-card" style="margin-top:16px;padding:16px">
                    <h3 style="margin:0 0 12px">Other</h3>
                    <label style="display:flex;align-items:center;gap:8px;margin:8px 0">
                        <input type="checkbox" id="dept-view-required-qty" ${ Number( department?.view_required_qty ?? 1 ) ? 'checked' : '' }>
                        <span>View Required Qty</span>
                    </label>
                </div>

                <div class="jsci-card" style="margin-top:16px;padding:16px">
                    <div style="display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap;margin-bottom:12px">
                        <h3 style="margin:0">Department Lines</h3>
                        <button type="button" class="jsci-btn jsci-btn--secondary" id="add-dept-line">
                            + Add Line
                        </button>
                    </div>
                    <div id="dept-lines-wrap"></div>
                </div>

                <button class="jsci-btn jsci-btn--primary" id="save-dept" style="margin-top:16px">
                    ${ isEditing ? 'Update Department' : 'Save Department' }
                </button>

                <div id="dept-notice" style="margin-top:10px"></div>
            </div>
        `;

        const renderLineRows = ( lines ) => {
            const wrap = document.getElementById('dept-lines-wrap');
            if (!wrap) {
                return;
            }

            wrap.innerHTML = lines.map( ( line, index ) => `
                <div class="jsci-form-row jsci-dept-line-row" data-index="${ index }" style="margin-bottom:12px;padding:12px;border:1px solid #dcdcde;border-radius:8px">
                    <div class="jsci-field">
                        <label>Line Name / Number</label>
                        <input type="text" class="dept-line-number" value="${ esc( line.line_number || '' ) }" placeholder="e.g. Line 1">
                    </div>
                    <div class="jsci-field">
                        <label>Sort Order</label>
                        <input type="number" class="dept-line-sort-order" value="${ esc( line.sort_order ?? index ) }">
                    </div>
                    <div class="jsci-field">
                        <label>Active</label>
                        <select class="dept-line-active">
                            <option value="1" ${ Number( line.is_active ?? 1 ) ? 'selected' : '' }>Yes</option>
                            <option value="0" ${ !Number( line.is_active ?? 1 ) ? 'selected' : '' }>No</option>
                        </select>
                    </div>
                    <div class="jsci-field" style="display:flex;align-items:flex-end">
                        <button type="button" class="jsci-btn jsci-btn--danger remove-dept-line" data-index="${ index }">
                            Remove
                        </button>
                    </div>
                </div>
            `).join('');

            wrap.querySelectorAll('.remove-dept-line').forEach((button) => {
                button.addEventListener('click', () => {
                    const nextLines = getLinePayload().filter((_, idx) => idx !== Number(button.dataset.index));
                    renderLineRows(nextLines);
                });
            });
        };

        const getLinePayload = () => Array.from(document.querySelectorAll('.jsci-dept-line-row')).map((row, index) => ({
            line_number: row.querySelector('.dept-line-number')?.value.trim() || '',
            sort_order: parseInt(row.querySelector('.dept-line-sort-order')?.value || index, 10),
            is_active: row.querySelector('.dept-line-active')?.value === '1'
        })).filter((line) => line.line_number);

        const syncSendDestinations = ( suffix = '' ) => {
            const idSuffix = suffix ? '-' + suffix : '';
            const classSuffix = suffix ? '-' + suffix : '';
            const wrap = document.getElementById(`dept-send-destinations-wrap${idSuffix}`);
            const allowSend = document.getElementById(`dept-allow-send-entry${idSuffix}`)?.checked;

            if (wrap) {
                wrap.style.display = allowSend ? 'block' : 'none';
            }

            const sendToAll = document.getElementById(`dept-send-to-all${idSuffix}`)?.checked;
            document.querySelectorAll(`.dept-send-destination${classSuffix}`).forEach(input => {
                input.disabled = !!sendToAll;
            });
        };
        const syncSendOutsidePurposes = ( suffix = '' ) => {
            const idSuffix = suffix ? '-' + suffix : '';
            const classSuffix = suffix ? '-' + suffix : '';
            const wrap = document.getElementById(`dept-send-outside-purposes-wrap${idSuffix}`);
            const allowSendOutsideFactory = document.getElementById(`dept-allow-send-outside-factory-entry${idSuffix}`)?.checked;

            if (wrap) {
                wrap.style.display = allowSendOutsideFactory ? 'block' : 'none';
            }

            document.querySelectorAll(`.dept-send-outside-purpose${classSuffix}`).forEach(input => {
                input.disabled = !allowSendOutsideFactory;
            });
        };
        const syncRejectStages = ( suffix = '' ) => {
            const idSuffix = suffix ? '-' + suffix : '';
            const classSuffix = suffix ? '-' + suffix : '';
            const wrap = document.getElementById(`dept-reject-stages-wrap${idSuffix}`);
            const allowReject = document.getElementById(`dept-allow-reject-entry${idSuffix}`)?.checked;

            if (wrap) {
                wrap.style.display = allowReject ? 'block' : 'none';
            }

            document.querySelectorAll(`.dept-reject-stage${classSuffix}`).forEach(input => {
                input.disabled = !allowReject;
            });
        };

        const getSendDestinationIds = ( suffix = '' ) => Array.from(document.querySelectorAll(`.dept-send-destination${suffix ? '-' + suffix : ''}:checked`))
            .map( input => parseInt( input.value, 10 ) )
            .filter( Boolean );
        const getSendOutsidePurposes = ( suffix = '' ) => Array.from(document.querySelectorAll(`.dept-send-outside-purpose${suffix ? '-' + suffix : ''}:checked`))
            .map( input => String( input.value || '' ) )
            .filter( Boolean );
        const getRejectStages = ( suffix = '' ) => Array.from(document.querySelectorAll(`.dept-reject-stage${suffix ? '-' + suffix : ''}:checked`))
            .map( input => String( input.value || '' ) )
            .filter( Boolean );
        const getCompatibilityTypes = () => Array.from(document.querySelectorAll('.dept-compatibility-type:checked'))
            .map( input => String( input.value || '' ) )
            .filter( Boolean );

        renderLineRows(initialLines);
        syncSendDestinations();
        syncSendDestinations('kg');
        syncSendOutsidePurposes();
        syncSendOutsidePurposes('kg');
        syncRejectStages();
        syncRejectStages('kg');

        document.getElementById('dept-allow-send-entry')
            ?.addEventListener('change', () => syncSendDestinations());
        document.getElementById('dept-send-to-all')
            ?.addEventListener('change', () => syncSendDestinations());
        document.getElementById('dept-allow-send-outside-factory-entry')
            ?.addEventListener('change', () => syncSendOutsidePurposes());
        document.getElementById('dept-allow-reject-entry')
            ?.addEventListener('change', () => syncRejectStages());
        document.getElementById('dept-allow-send-entry-kg')
            ?.addEventListener('change', () => syncSendDestinations('kg'));
        document.getElementById('dept-send-to-all-kg')
            ?.addEventListener('change', () => syncSendDestinations('kg'));
        document.getElementById('dept-allow-send-outside-factory-entry-kg')
            ?.addEventListener('change', () => syncSendOutsidePurposes('kg'));
        document.getElementById('dept-allow-reject-entry-kg')
            ?.addEventListener('change', () => syncRejectStages('kg'));

        document.getElementById('add-dept-line')
            ?.addEventListener('click', () => {
                const nextLines = getLinePayload();
                nextLines.push({ line_number: '', sort_order: nextLines.length, is_active: 1 });
                renderLineRows(nextLines);
            });

        document.getElementById('cancel-dept-form')
            ?.addEventListener('click', () => {
                formWrap.innerHTML = '';
            });

        document.getElementById('jsci-department-form-card')
            ?.scrollIntoView({ behavior: 'smooth', block: 'start' });

        document.getElementById('save-dept')
            .addEventListener('click', async () => {
                try {
                    const body = {
                        name: document.getElementById('dept-name').value,
                        tx_prefix: document.getElementById('dept-prefix').value,
                        workflow_order: parseInt(
                            document.getElementById('dept-order').value,
                            10
                        ),
                        is_active: document.getElementById('dept-active').value === '1',
                        allow_manual_entry: document.getElementById('dept-allow-manual-entry').checked,
                        allow_production_entry: document.getElementById('dept-allow-production-entry').checked,
                        allow_fabric_to_piece_conversion_entry: document.getElementById('dept-allow-fabric-to-piece-conversion-entry').checked,
                        auto_produce_on_receive: document.getElementById('dept-auto-produce-on-receive').checked,
                        allow_reject_entry: document.getElementById('dept-allow-reject-entry').checked,
                        reject_stages: getRejectStages(),
                        allow_send_entry: document.getElementById('dept-allow-send-entry').checked,
                        allow_send_outside_factory_entry: document.getElementById('dept-allow-send-outside-factory-entry').checked,
                        send_outside_purposes: getSendOutsidePurposes(),
                        allow_manual_entry_kg: document.getElementById('dept-allow-manual-entry-kg').checked,
                        allow_production_entry_kg: document.getElementById('dept-allow-production-entry-kg').checked,
                        auto_produce_on_receive_kg: document.getElementById('dept-auto-produce-on-receive-kg').checked,
                        allow_reject_entry_kg: document.getElementById('dept-allow-reject-entry-kg').checked,
                        reject_stages_kg: getRejectStages('kg'),
                        allow_send_entry_kg: document.getElementById('dept-allow-send-entry-kg').checked,
                        allow_send_outside_factory_entry_kg: document.getElementById('dept-allow-send-outside-factory-entry-kg').checked,
                        send_outside_purposes_kg: getSendOutsidePurposes('kg'),
                        view_required_qty: document.getElementById('dept-view-required-qty').checked,
                        compatibility_transaction_types: getCompatibilityTypes(),
                        send_to_department_ids: document.getElementById('dept-send-to-all').checked ? null : getSendDestinationIds(),
                        send_to_department_ids_kg: document.getElementById('dept-send-to-all-kg').checked ? null : getSendDestinationIds('kg'),
                        lines: getLinePayload()
                    };

                    await api(
                        isEditing ? `/departments/${ department.id }` : '/departments',
                        {
                            method: isEditing ? 'PUT' : 'POST',
                            body
                        }
                    );

                    document.getElementById('dept-notice').innerHTML =
                        notice(
                            isEditing
                                ? 'Department updated successfully.'
                                : 'Department created successfully.',
                            'success'
                        );

                    setTimeout(() => renderDepartments(), 1000);
                } catch (err) {
                    document.getElementById('dept-notice').innerHTML =
                        notice(err.message, 'error');
                }
            });
    }

    // ── Reports ───────────────────────────────────────────────────────────────

    async function renderExternalOrganizations() {
        $app.innerHTML = loader();

        try {
            const data = await api( '/external-organizations' );
            const active = data.active || [];
            const voided = data.voided || [];
            const activeRows = active.map( name => `
                <tr>
                    <td><strong>${ esc( name ) }</strong></td>
                    <td><span class="jsci-badge jsci-badge--confirmed">Active</span></td>
                    <td>
                        <button type="button" class="jsci-btn jsci-btn--danger jsci-void-external-org" data-name="${ esc( name ) }">
                            Void
                        </button>
                    </td>
                </tr>
            ` ).join('');
            const voidedRows = voided.map( name => `
                <tr style="opacity:.68">
                    <td><strong>${ esc( name ) }</strong></td>
                    <td><span class="jsci-badge jsci-badge--voided">Voided</span></td>
                    <td>
                        <button type="button" class="jsci-btn jsci-btn--secondary jsci-restore-external-org" data-name="${ esc( name ) }">
                            Add back
                        </button>
                    </td>
                </tr>
            ` ).join('');

            $app.innerHTML = `
                <div class="jsci-prm-wrap">
                    <div style="display:flex;justify-content:space-between;align-items:end;gap:16px;flex-wrap:wrap">
                        <div>
                            <h1 style="margin-bottom:8px">External organizations</h1>
                            <p style="margin:0;color:#64748b">Manage the names shown in the Production Report Entry external organization dropdown.</p>
                        </div>
                    </div>

                    <div class="jsci-card" style="max-width:640px;margin-top:20px">
                        <h2 style="margin-top:0">Add External organization</h2>
                        <div class="jsci-form-row" style="grid-template-columns:minmax(220px,1fr) auto;align-items:end">
                            <div class="jsci-field">
                                <label>Name</label>
                                <input id="jsci-external-org-name" type="text" placeholder="External organization name">
                            </div>
                            <button type="button" class="jsci-btn jsci-btn--primary" id="jsci-add-external-org">Add</button>
                        </div>
                        <div id="jsci-external-org-notice"></div>
                    </div>

                    <div class="jsci-card" style="margin-top:20px">
                        <h2 style="margin-top:0">Active External organizations</h2>
                        <table class="jsci-table">
                            <thead><tr><th>Name</th><th>Status</th><th>Action</th></tr></thead>
                            <tbody>${ activeRows || '<tr><td colspan="3">No active External organizations found.</td></tr>' }</tbody>
                        </table>
                    </div>

                    <div class="jsci-card" style="margin-top:20px">
                        <h2 style="margin-top:0">Voided External organizations</h2>
                        <table class="jsci-table">
                            <thead><tr><th>Name</th><th>Status</th><th>Action</th></tr></thead>
                            <tbody>${ voidedRows || '<tr><td colspan="3">No voided External organizations found.</td></tr>' }</tbody>
                        </table>
                    </div>
                </div>
            `;

            document.getElementById( 'jsci-add-external-org' )?.addEventListener( 'click', async () => {
                const input = document.getElementById( 'jsci-external-org-name' );
                const name = input.value.trim();
                const output = document.getElementById( 'jsci-external-org-notice' );

                if ( ! name ) {
                    output.innerHTML = notice( 'External organization name is required.', 'error' );
                    input.focus();
                    return;
                }

                output.innerHTML = loader();

                try {
                    await api( '/external-organizations', {
                        method: 'POST',
                        body: { name },
                    } );
                    await renderExternalOrganizations();
                } catch ( err ) {
                    output.innerHTML = notice( err.message, 'error' );
                }
            } );

            document.querySelectorAll( '.jsci-void-external-org' ).forEach( button => {
                button.addEventListener( 'click', async function () {
                    if ( ! confirm( `Void ${ this.dataset.name }? It will be removed from the report entry dropdown.` ) ) {
                        return;
                    }

                    try {
                        await api( '/external-organizations/void', {
                            method: 'POST',
                            body: { name: this.dataset.name },
                        } );
                        await renderExternalOrganizations();
                    } catch ( err ) {
                        alert( err.message );
                    }
                } );
            } );

            document.querySelectorAll( '.jsci-restore-external-org' ).forEach( button => {
                button.addEventListener( 'click', async function () {
                    try {
                        await api( '/external-organizations', {
                            method: 'POST',
                            body: { name: this.dataset.name },
                        } );
                        await renderExternalOrganizations();
                    } catch ( err ) {
                        alert( err.message );
                    }
                } );
            } );
        } catch ( err ) {
            $app.innerHTML = notice( err.message, 'error' );
        }
    }

    async function renderReports() {
        $app.innerHTML = `
            <div class="jsci-prm-wrap">
                <h1>Reports</h1>
                <div class="jsci-card" style="max-width:400px">
                    <div class="jsci-field">
                        <label>Order ID</label>
                        <input id="jsci-report-order-id" type="number" min="1" placeholder="Enter order ID">
                    </div>
                    <div style="display:flex;gap:10px;margin-top:12px">
                        <button class="jsci-btn jsci-btn--primary" id="jsci-run-summary">Size summary</button>
                        <button class="jsci-btn jsci-btn--secondary" id="jsci-run-kpi">KPI report</button>
                    </div>
                </div>
                <div id="jsci-report-output" style="margin-top:24px"></div>
            </div>
        `;

        document.getElementById( 'jsci-run-summary' ).addEventListener( 'click', async () => {
            const id  = document.getElementById( 'jsci-report-order-id' ).value;
            const out = document.getElementById( 'jsci-report-output' );
            if ( ! id ) { out.innerHTML = notice( 'Please enter an Order ID.', 'info' ); return; }
            out.innerHTML = loader();
            try {
                const data = await api( `/reports/order-summary/${ id }` );
                out.innerHTML = summaryTable( data );
            } catch ( err ) {
                out.innerHTML = notice( err.message, 'error' );
            }
        } );

        document.getElementById( 'jsci-run-kpi' ).addEventListener( 'click', async () => {
            const id  = document.getElementById( 'jsci-report-order-id' ).value;
            const out = document.getElementById( 'jsci-report-output' );
            if ( ! id ) { out.innerHTML = notice( 'Please enter an Order ID.', 'info' ); return; }
            out.innerHTML = loader();
            try {
                const data = await api( `/reports/kpi/${ id }` );
                out.innerHTML = kpiTable( data );
            } catch ( err ) {
                out.innerHTML = notice( err.message, 'error' );
            }
        } );
    }

    function summaryTable( data ) {
        const rows = data.sizes.map( s => `
            <tr>
                <td>${ esc( s.size_label ) }</td>
                <td>${ s.required_qty }</td>
                <td>${ s.produced_qty }</td>
                <td>${ s.remaining }</td>
                <td>
                    <div style="background:#eee;border-radius:4px;height:8px;width:120px;overflow:hidden">
                        <div style="background:#00a32a;height:100%;width:${ Math.min( s.pct_complete, 100 ) }%"></div>
                    </div>
                    ${ s.pct_complete }%
                </td>
            </tr>
        ` ).join('');

        return `
            <h2>Order #${ data.order_id } – size summary</h2>
            <table class="jsci-table">
                <thead><tr><th>Size</th><th>Required</th><th>Produced</th><th>Remaining</th><th>Progress</th></tr></thead>
                <tbody>${ rows }</tbody>
            </table>
        `;
    }

    function kpiTable( data ) {
        const rows = data.kpi.map( k => `
            <tr>
                <td>${ esc( k.size_label ) }</td>
                <td>${ k.required_qty }</td>
                <td>${ k.produced_qty }</td>
                <td><span class="jsci-badge jsci-badge--${ k.kpi.toLowerCase().replace('_','-') }">${ k.kpi }</span></td>
                <td>${ k.days_remaining !== null ? k.days_remaining + ' days' : '—' }</td>
            </tr>
        ` ).join('');

        return `
            <h2>KPI – ${ esc( data.buyer ) } | Deadline: ${ data.deadline || '—' }</h2>
            <table class="jsci-table">
                <thead><tr><th>Size</th><th>Required</th><th>Produced</th><th>KPI</th><th>Days remaining</th></tr></thead>
                <tbody>${ rows }</tbody>
            </table>
        `;
    }

    // ── Audit log ─────────────────────────────────────────────────────────────

    async function renderAudit() {
        $app.innerHTML = loader();

        try {
            const logs = await api( '/reports/audit-log?limit=50' );
            const rows = logs.map( l => `
                <tr>
                    <td>${ esc( l.id ) }</td>
                    <td>${ esc( l.user_id ) }</td>
                    <td><code>${ esc( l.action ) }</code></td>
                    <td>${ esc( l.object_type ) } #${ esc( l.object_id ) }</td>
                    <td style="font-size:11px;color:#50575e">${ esc( l.created_at ) }</td>
                    <td style="font-size:11px">${ esc( l.ip_address ) }</td>
                </tr>
            ` ).join('');

            $app.innerHTML = `
                <div class="jsci-prm-wrap">
                    <h1>Audit log</h1>
                    <table class="jsci-table">
                        <thead><tr>
                            <th>#</th><th>User</th><th>Action</th><th>Object</th><th>Time</th><th>IP</th>
                        </tr></thead>
                        <tbody>${ rows || '<tr><td colspan="6">No log entries.</td></tr>' }</tbody>
                    </table>
                </div>
            `;
        } catch ( err ) {
            $app.innerHTML = notice( err.message, 'error' );
        }
    }

    // ── Settings ──────────────────────────────────────────────────────────────

    function renderSettings() {
        $app.innerHTML = `
            <div class="jsci-prm-wrap">
                <h1>Settings</h1>
                <div class="jsci-card" style="max-width:480px">
                    <p><strong>Plugin version:</strong> ${ esc( jsciPrm.version ) }</p>
                    <p><strong>API namespace:</strong> <code>jsci-prm/v1</code></p>
                    <p><strong>Your role:</strong> <code>${ esc( jsciPrm.currentUser.role || 'none' ) }</code></p>
                </div>
                <div class="jsci-card" style="max-width:480px;margin-top:20px">
                    <h2 style="margin-top:0">Danger zone</h2>
                    <p style="color:#50575e;font-size:13px">
                        To remove all PRM data on uninstall, enable the option below.
                        This cannot be undone.
                    </p>
                    <label style="display:flex;align-items:center;gap:8px;font-size:13px">
                        <input type="checkbox" id="jsci-remove-data"
                            ${ jsciPrm.removeDataOnUninstall ? 'checked' : '' }>
                        Remove all data when plugin is uninstalled
                    </label>
                    <button class="jsci-btn jsci-btn--danger" style="margin-top:12px" id="jsci-save-settings">
                        Save settings
                    </button>
                    <div id="jsci-settings-notice"></div>
                </div>
            </div>
        `;

        document.getElementById( 'jsci-save-settings' ).addEventListener( 'click', async () => {
            const remove = document.getElementById( 'jsci-remove-data' ).checked ? '1' : '0';
            // Simple nonce-protected AJAX to wp-admin/admin-ajax.php could be added here.
            // For now we just update the option via a future AJAX handler.
            document.getElementById( 'jsci-settings-notice' ).innerHTML =
                notice( 'Settings handler coming in next module build.', 'info' );
        } );
    }

    // ── Shared component helpers ──────────────────────────────────────────────

    async function renderAccessManagement() {
        const users = jsciPrm.accessManagement?.users || [];
        const params = new URLSearchParams( window.location.search );
        const selectedUserId = params.get( 'user_id' );
        const editUserId = params.get( 'edit_user_id' );
        const isAddingUser = params.get( 'action' ) === 'add_user';

        if ( isAddingUser ) {
            renderAccessManagementUserForm();
            return;
        }

        if ( editUserId ) {
            const selectedUser = users.find( user => String( user.id ) === String( editUserId ) );

            if ( selectedUser ) {
                renderAccessManagementUserForm( selectedUser );
                return;
            }
        }

        if ( selectedUserId ) {
            const selectedUser = users.find( user => String( user.id ) === String( selectedUserId ) );

            if ( selectedUser ) {
                await renderAccessManagementDetail( selectedUser );
                return;
            }
        }

        const rows = users.map( user => `
            <tr${ user.inactive ? ' style="opacity:.58"' : '' }>
                <td>
                    <strong>${ esc( user.name ) }</strong><br>
                    <span style="color:#64748b">${ esc( user.email ) }</span>
                    ${ user.inactive ? '<br><span style="color:#b91c1c;font-weight:700">Inactive</span>' : '' }
                </td>
                <td>${ esc( user.designation || '-' ) }</td>
                <td>${ esc( user.userType ) }</td>
                <td>${ user.wpRoles.length ? user.wpRoles.map( role => `<code>${ esc( role ) }</code>` ).join(' ') : '-' }</td>
                <td>
                    <div style="display:flex;gap:8px;flex-wrap:wrap">
                        <button type="button" class="jsci-btn jsci-btn--primary jsci-manage-access-btn" data-user-id="${ user.id }">
                            Manage access
                        </button>
                        ${
                            user.wpRoles.includes( 'administrator' )
                                ? ''
                                : `
                                    <button type="button" class="jsci-btn jsci-btn--secondary jsci-edit-user-btn" data-user-id="${ user.id }">
                                        Edit user
                                    </button>
                                    <button type="button" class="jsci-btn jsci-btn--danger jsci-delete-user-btn" data-user-id="${ user.id }">
                                        Delete user
                                    </button>
                                `
                        }
                    </div>
                </td>
            </tr>
        ` ).join('');

        $app.innerHTML = `
            <div class="jsci-prm-wrap">
                <div style="display:flex;justify-content:space-between;align-items:end;gap:16px;flex-wrap:wrap">
                    <div>
                        <h1 style="margin-bottom:8px">Access Management</h1>
                        <p style="margin:0;color:#64748b">Create users, edit profiles, and manage app access from one place.</p>
                    </div>
                    <button type="button" class="jsci-btn jsci-btn--primary" id="jsci-add-user-btn">Add user</button>
                </div>

                <div class="jsci-card" style="margin-top:20px">
                    <table class="jsci-table">
                        <thead>
                            <tr>
                                <th>User</th>
                                <th>Designation</th>
                                <th>User type</th>
                                <th>WP roles</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody>${ rows || '<tr><td colspan="5">No users found.</td></tr>' }</tbody>
                    </table>
                </div>
            </div>
        `;

        document.getElementById('jsci-add-user-btn')?.addEventListener('click', () => {
            const url = new URL( window.location.href );
            url.searchParams.delete( 'user_id' );
            url.searchParams.delete( 'edit_user_id' );
            url.searchParams.set( 'action', 'add_user' );
            window.location.href = url.toString();
        } );

        document.querySelectorAll('.jsci-manage-access-btn').forEach( button => {
            button.addEventListener('click', function () {
                const url = new URL( window.location.href );
                url.searchParams.delete( 'action' );
                url.searchParams.delete( 'edit_user_id' );
                url.searchParams.set( 'user_id', this.dataset.userId );
                window.location.href = url.toString();
            } );
        } );

        document.querySelectorAll('.jsci-edit-user-btn').forEach( button => {
            button.addEventListener('click', function () {
                const url = new URL( window.location.href );
                url.searchParams.delete( 'action' );
                url.searchParams.delete( 'user_id' );
                url.searchParams.set( 'edit_user_id', this.dataset.userId );
                window.location.href = url.toString();
            } );
        } );

        document.querySelectorAll('.jsci-delete-user-btn').forEach( button => {
            button.addEventListener('click', async function () {
                const user = users.find( item => String( item.id ) === String( this.dataset.userId ) );

                if ( ! confirm( `Deactivate ${ user?.name || 'this user' } and remove all app access?` ) ) {
                    return;
                }

                try {
                    await api( `/access-management/users/${ this.dataset.userId }`, { method: 'DELETE' } );
                    window.location.reload();
                } catch ( err ) {
                    alert( err.message );
                }
            } );
        } );
    }

    function renderAccessManagementUserForm( user = null ) {
        const roleOptions = jsciPrm.accessManagement?.roleOptions || [];
        const isEdit = !!user;
        const currentRole = roleOptions.find( option => user?.wpRoles?.includes( option.key ) )?.key || 'jsci_employee';
        const roleOptionHtml = roleOptions.map( option => `
            <option value="${ esc( option.key ) }" ${ currentRole === option.key ? 'selected' : '' }>
                ${ esc( option.label ) }
            </option>
        ` ).join('');

        $app.innerHTML = `
            <div class="jsci-prm-wrap">
                <div style="display:flex;justify-content:space-between;gap:16px;flex-wrap:wrap;align-items:start">
                    <div>
                        <h1 style="margin:0 0 6px">${ isEdit ? 'Edit User' : 'Add User' }</h1>
                        <p style="margin:0;color:#64748b">${ isEdit ? 'Update this user profile and app role.' : 'Create a new app user.' }</p>
                    </div>
                    <button type="button" class="jsci-btn jsci-btn--secondary" id="jsci-user-form-back">Back to users</button>
                </div>

                <div class="jsci-card" style="margin-top:20px;max-width:760px">
                    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(240px,1fr));gap:16px">
                        <div class="jsci-field">
                            <label>Username</label>
                            <input id="jsci-user-username" type="text" value="${ esc( user?.username || '' ) }" ${ isEdit ? 'disabled' : '' }>
                        </div>
                        <div class="jsci-field">
                            <label>Email *</label>
                            <input id="jsci-user-email" type="email" value="${ esc( user?.email || '' ) }">
                        </div>
                        <div class="jsci-field">
                            <label>First Name</label>
                            <input id="jsci-user-first-name" type="text" value="${ esc( user?.firstName || '' ) }">
                        </div>
                        <div class="jsci-field">
                            <label>Last Name</label>
                            <input id="jsci-user-last-name" type="text" value="${ esc( user?.lastName || '' ) }">
                        </div>
                        <div class="jsci-field">
                            <label>Designation</label>
                            <input id="jsci-user-designation" type="text" value="${ esc( user?.designation || '' ) }">
                        </div>
                        <div class="jsci-field">
                            <label>Role</label>
                            <select id="jsci-user-role">${ roleOptionHtml }</select>
                        </div>
                        <div class="jsci-field">
                            <label>${ isEdit ? 'New Password' : 'New Password *' }</label>
                            <input id="jsci-user-password" type="password" autocomplete="new-password">
                        </div>
                    </div>

                    <div id="jsci-user-form-notice"></div>
                    <div style="margin-top:18px;display:flex;gap:10px;flex-wrap:wrap">
                        <button type="button" class="jsci-btn jsci-btn--primary" id="jsci-save-user">${ isEdit ? 'Save user' : 'Add user' }</button>
                        <button type="button" class="jsci-btn jsci-btn--secondary" id="jsci-user-form-cancel">Cancel</button>
                    </div>
                </div>
            </div>
        `;

        const goBack = () => {
            const url = new URL( window.location.href );
            url.searchParams.delete( 'action' );
            url.searchParams.delete( 'edit_user_id' );
            url.searchParams.delete( 'user_id' );
            window.location.href = url.toString();
        };

        document.getElementById('jsci-user-form-back')?.addEventListener('click', goBack);
        document.getElementById('jsci-user-form-cancel')?.addEventListener('click', goBack);

        document.getElementById('jsci-save-user')?.addEventListener('click', async () => {
            const noticeWrap = document.getElementById('jsci-user-form-notice');
            const button = document.getElementById('jsci-save-user');
            const body = {
                username: document.getElementById('jsci-user-username').value.trim(),
                first_name: document.getElementById('jsci-user-first-name').value.trim(),
                last_name: document.getElementById('jsci-user-last-name').value.trim(),
                email: document.getElementById('jsci-user-email').value.trim(),
                password: document.getElementById('jsci-user-password').value,
                designation: document.getElementById('jsci-user-designation').value.trim(),
                role: document.getElementById('jsci-user-role').value,
            };

            try {
                button.disabled = true;
                noticeWrap.innerHTML = notice( 'Saving user...', 'info' );

                await api(
                    isEdit ? `/access-management/users/${ user.id }/profile` : '/access-management/users',
                    {
                        method: isEdit ? 'PUT' : 'POST',
                        body,
                    }
                );

                noticeWrap.innerHTML = notice( 'User saved.', 'success' );
                setTimeout( goBack, 500 );
            } catch ( err ) {
                button.disabled = false;
                noticeWrap.innerHTML = notice( err.message, 'error' );
            }
        } );
    }

    async function renderAccessManagementDetail( user ) {
        const matrix = jsciPrm.accessManagement?.productionEntryMatrix || [];
        const entryTypes = jsciPrm.accessManagement?.entryTypes || [];
        const orderAccessOptions = jsciPrm.accessManagement?.orderAccessOptions || [];
        const transactionAccessOptions = jsciPrm.accessManagement?.transactionAccessOptions || [];
        const MessageAccessOptions = jsciPrm.accessManagement?.MessageAccessOptions || [];

        $app.innerHTML = loader();

        let savedAccess = [];
        let savedOrderAccess = {};
        let savedTransactionAccess = {};
        let savedMessageAccess = {};

        try {
            const response = await api( `/access-management/users/${ user.id }` );
            savedAccess = response.production_entry || [];
            savedOrderAccess = response.order_management || {};
            savedTransactionAccess = response.transaction_management || {};
            savedMessageAccess = response.Message_management || {};
        } catch ( err ) {
            $app.innerHTML = notice( err.message, 'error' );
            return;
        }

        const savedByDepartment = Object.fromEntries(
            savedAccess.map( item => [ String( item.dept_id ), item ] )
        );

        const departmentCards = matrix.map( department => {
            const saved = savedByDepartment[ String( department.id ) ] || {};
            const savedLines = saved.line_access || [];
            const savedEntryTypes = saved.entry_types || [];
            const savedHistoryAccess = !!saved.history_access;
            const savedDepartmentHistoryAccess = !!saved.department_history_access;
            const hasLines = Array.isArray( department.lines ) && department.lines.length > 0;

            const lineOptions = hasLines
                ? department.lines.map( line => `
                    <label style="display:flex;align-items:center;gap:8px">
                        <input
                            type="checkbox"
                            class="jsci-production-entry-line"
                            data-dept-id="${ department.id }"
                            value="${ esc( line.line_number ) }"
                            ${ savedLines.includes( line.line_number ) ? 'checked' : '' }>
                        <span>${ esc( line.line_number ) }</span>
                    </label>
                ` ).join('')
                : '<span style="color:#64748b;font-size:12px">No line-specific access for this department.</span>';

            const collectiveOption = hasLines ? `
                <label style="display:flex;align-items:center;gap:8px">
                    <input
                        type="checkbox"
                        class="jsci-production-entry-line"
                        data-dept-id="${ department.id }"
                        value="Collective"
                        ${ savedLines.includes( 'Collective' ) ? 'checked' : '' }>
                    <span>Collective</span>
                </label>
            ` : '';

            const typeOptions = entryTypes.map( type => `
                <label style="display:flex;align-items:center;gap:8px">
                    <input
                        type="checkbox"
                        class="jsci-production-entry-type"
                        data-dept-id="${ department.id }"
                        value="${ esc( type.key ) }"
                        ${ savedEntryTypes.includes( type.key ) ? 'checked' : '' }>
                    <span>${ esc( type.label ) }</span>
                </label>
            ` ).join('');

            return `
                <div class="jsci-card" style="margin-top:16px" data-dept-card="${ department.id }">
                    <div style="display:flex;justify-content:space-between;gap:16px;flex-wrap:wrap;align-items:start">
                        <label style="display:flex;align-items:center;gap:10px;font-size:16px;font-weight:700">
                            <input
                                type="checkbox"
                                class="jsci-production-entry-dept"
                                data-dept-id="${ department.id }"
                                ${ saved.dept_access ? 'checked' : '' }>
                            <span>${ esc( department.name ) }</span>
                        </label>
                    </div>

                    <div style="margin-top:18px;display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:24px">
                        <div>
                            <h3 style="margin-top:0;margin-bottom:10px;font-size:15px">Line access</h3>
                            <div style="display:grid;gap:10px">${ lineOptions }${ collectiveOption }</div>
                        </div>
                        <div>
                            <h3 style="margin-top:0;margin-bottom:10px;font-size:15px">Entry types</h3>
                            <div style="display:grid;gap:10px">${ typeOptions }</div>
                        </div>
                        <div>
                            <h3 style="margin-top:0;margin-bottom:10px;font-size:15px">History access</h3>
                            <div style="display:grid;gap:10px">
                                <label style="display:flex;align-items:center;gap:8px">
                                    <input
                                        type="checkbox"
                                        class="jsci-production-entry-history"
                                        data-dept-id="${ department.id }"
                                        value="history"
                                        ${ savedHistoryAccess ? 'checked' : '' }>
                                    <span>History</span>
                                </label>
                                <label style="display:flex;align-items:center;gap:8px">
                                    <input
                                        type="checkbox"
                                        class="jsci-production-entry-history"
                                        data-dept-id="${ department.id }"
                                        value="department_history"
                                        ${ savedDepartmentHistoryAccess ? 'checked' : '' }>
                                    <span>Department History</span>
                                </label>
                            </div>
                        </div>
                    </div>
                </div>
            `;
        } ).join('');

        const orderManagementOptions = orderAccessOptions.map( option => `
            <label style="display:flex;align-items:center;gap:8px">
                <input
                    type="checkbox"
                    class="jsci-order-management-access"
                    value="${ esc( option.key ) }"
                    ${ savedOrderAccess[ option.key ] ? 'checked' : '' }>
                <span>${ esc( option.label ) }</span>
            </label>
        ` ).join('');

        const transactionManagementOptions = transactionAccessOptions.map( option => `
            <label style="display:flex;align-items:center;gap:8px">
                <input
                    type="checkbox"
                    class="jsci-transaction-management-access"
                    value="${ esc( option.key ) }"
                    ${ savedTransactionAccess[ option.key ] ? 'checked' : '' }>
                <span>${ esc( option.label ) }</span>
            </label>
        ` ).join('');

        const MessageManagementOptions = MessageAccessOptions.map( option => `
            <label style="display:flex;align-items:center;gap:8px">
                <input
                    type="checkbox"
                    class="jsci-Message-management-access"
                    value="${ esc( option.key ) }"
                    ${ savedMessageAccess[ option.key ] ? 'checked' : '' }>
                <span>${ esc( option.label ) }</span>
            </label>
        ` ).join('');

        $app.innerHTML = `
            <div class="jsci-prm-wrap">
                <div style="display:flex;justify-content:space-between;gap:16px;flex-wrap:wrap;align-items:start">
                    <div>
                        <h1 style="margin:0 0 6px">Manage Access</h1>
                        <p style="margin:0;color:#64748b">Set up department, line, and entry-type access for this user.</p>
                    </div>
                    <button type="button" class="jsci-btn jsci-btn--secondary" id="jsci-access-back-btn">Back to users</button>
                </div>

                <div class="jsci-card" style="margin-top:20px">
                    <h2 style="margin-top:0;margin-bottom:6px">${ esc( user.name ) }</h2>
                    <p style="margin:0;color:#64748b">${ esc( user.email ) }</p>
                    <p style="margin:8px 0 0;color:#1d2327"><strong>User type:</strong> ${ esc( user.userType ) }</p>
                </div>

                <div class="jsci-card" style="margin-top:20px">
                    <div style="display:flex;justify-content:space-between;gap:16px;flex-wrap:wrap;align-items:start">
                        <div>
                            <h2 style="margin:0 0 6px">Transaction Management</h2>
                            <p style="margin:0;color:#64748b">Choose what this user can do in the Transactions page.</p>
                        </div>
                    </div>
                    <div style="margin-top:16px;display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:12px">
                        ${ transactionManagementOptions || '<span style="color:#64748b">No transaction access options found.</span>' }
                    </div>
                </div>

                <div class="jsci-card" style="margin-top:20px">
                    <div style="display:flex;justify-content:space-between;gap:16px;flex-wrap:wrap;align-items:start">
                        <div>
                            <h2 style="margin:0 0 6px">Message Page Access</h2>
                            <p style="margin:0;color:#64748b">Choose whether this user can create and manage frontend headline Messages.</p>
                        </div>
                    </div>
                    <div style="margin-top:16px;display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:12px">
                        ${ MessageManagementOptions || '<span style="color:#64748b">No Message access options found.</span>' }
                    </div>
                </div>

                <div class="jsci-card" style="margin-top:20px">
                    <div style="display:flex;justify-content:space-between;gap:16px;flex-wrap:wrap;align-items:start">
                        <div>
                            <h2 style="margin:0 0 6px">Order Management</h2>
                            <p style="margin:0;color:#64748b">Choose what this user can do in order management.</p>
                        </div>
                    </div>
                    <div style="margin-top:16px;display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:12px">
                        ${ orderManagementOptions || '<span style="color:#64748b">No order access options found.</span>' }
                    </div>
                </div>

                <div class="jsci-card" style="margin-top:20px">
                    <div style="display:flex;justify-content:space-between;gap:16px;flex-wrap:wrap;align-items:start">
                        <div>
                            <h2 style="margin:0 0 6px">Production Report Entry Management</h2>
                        <p style="margin:0;color:#64748b">Choose which departments, lines, Collective entry, transaction types, incoming transfer acceptance, and history tools this user may use in the Production Entry page.</p>
                        </div>
                    </div>
                </div>

                <div id="jsci-production-entry-access-list">${ departmentCards || '<div class="jsci-card" style="margin-top:16px">No departments found.</div>' }</div>

                <div id="jsci-production-entry-notice" style="margin-top:16px"></div>

                <div style="margin-top:16px;display:flex;gap:10px;flex-wrap:wrap">
                    <button type="button" class="jsci-btn jsci-btn--primary" id="jsci-save-production-entry-access">Save access</button>
                    <button type="button" class="jsci-btn jsci-btn--secondary" id="jsci-reset-production-entry-access">Reset changes</button>
                </div>
            </div>
        `;

        document.getElementById('jsci-access-back-btn')?.addEventListener('click', () => {
            const url = new URL( window.location.href );
            url.searchParams.delete( 'user_id' );
            window.location.href = url.toString();
        } );

        document.querySelectorAll('.jsci-production-entry-line').forEach( checkbox => {
            checkbox.addEventListener('change', function () {
                if ( this.checked ) {
                    const departmentToggle = document.querySelector(`.jsci-production-entry-dept[data-dept-id="${ this.dataset.deptId }"]`);
                    if ( departmentToggle ) {
                        departmentToggle.checked = true;
                    }
                }
            } );
        } );

        document.querySelectorAll('.jsci-production-entry-history').forEach( checkbox => {
            checkbox.addEventListener('change', function () {
                if ( this.checked ) {
                    const departmentToggle = document.querySelector(`.jsci-production-entry-dept[data-dept-id="${ this.dataset.deptId }"]`);
                    if ( departmentToggle ) {
                        departmentToggle.checked = true;
                    }
                }
            } );
        } );

        document.getElementById('jsci-reset-production-entry-access')?.addEventListener('click', () => {
            renderAccessManagementDetail( user );
        } );

        document.getElementById('jsci-save-production-entry-access')?.addEventListener('click', async () => {
            const noticeWrap = document.getElementById('jsci-production-entry-notice');
            const payload = [];
            const orderManagementPayload = {};
            const transactionManagementPayload = {};
            const MessageManagementPayload = {};

            for ( const department of matrix ) {
                const deptId = String( department.id );
                const deptToggle = document.querySelector(`.jsci-production-entry-dept[data-dept-id="${ deptId }"]`);
                const selectedLines = Array.from( document.querySelectorAll(`.jsci-production-entry-line[data-dept-id="${ deptId }"]:checked`) ).map( item => item.value );
                const selectedTypes = Array.from( document.querySelectorAll(`.jsci-production-entry-type[data-dept-id="${ deptId }"]:checked`) ).map( item => item.value );
                const selectedHistory = Array.from( document.querySelectorAll(`.jsci-production-entry-history[data-dept-id="${ deptId }"]:checked`) ).map( item => item.value );
                const hasLines = Array.isArray( department.lines ) && department.lines.length > 0;
                const needsLineAccess = hasLines && selectedTypes.includes( 'produce' );
                const historyAccess = selectedHistory.includes( 'history' );
                const departmentHistoryAccess = selectedHistory.includes( 'department_history' );

                if ( ! deptToggle?.checked ) {
                    continue;
                }

                if ( needsLineAccess && ! selectedLines.length ) {
                    noticeWrap.innerHTML = notice( `${ department.name }: select at least one line or Collective.`, 'error' );
                    return;
                }

                if ( ! selectedTypes.length && ! historyAccess && ! departmentHistoryAccess ) {
                    noticeWrap.innerHTML = notice( `${ department.name }: select at least one entry type, History, or Department History.`, 'error' );
                    return;
                }

                payload.push( {
                    dept_id: department.id,
                    dept_access: true,
                    line_access: selectedLines,
                    entry_types: selectedTypes,
                    history_access: historyAccess,
                    department_history_access: departmentHistoryAccess,
                } );
            }

            document.querySelectorAll('.jsci-order-management-access').forEach( checkbox => {
                orderManagementPayload[ checkbox.value ] = checkbox.checked;
            } );

            document.querySelectorAll('.jsci-transaction-management-access').forEach( checkbox => {
                transactionManagementPayload[ checkbox.value ] = checkbox.checked;
            } );

            document.querySelectorAll('.jsci-Message-management-access').forEach( checkbox => {
                MessageManagementPayload[ checkbox.value ] = checkbox.checked;
            } );

            try {
                noticeWrap.innerHTML = notice( 'Saving access...', 'info' );

                await api( `/access-management/users/${ user.id }`, {
                    method: 'PUT',
                    body: {
                        production_entry: payload,
                        order_management: orderManagementPayload,
                        transaction_management: transactionManagementPayload,
                        Message_management: MessageManagementPayload,
                    },
                } );

                noticeWrap.innerHTML = notice( 'Access saved.', 'success' );
                setTimeout( () => {
                    renderAccessManagementDetail( user );
                }, 300 );
            } catch ( err ) {
                noticeWrap.innerHTML = notice( err.message, 'error' );
            }
        } );
    }

    function txTable( txs ) {

        const rows = txs.map( tx => `

            <tr>

                <td>
                    <code style="font-size:11px">
                        ${ esc( tx.tx_number ) }
                    </code>
                </td>

                <td>#${ esc( tx.order_id ) }</td>

                <td>${ esc( formatTransactionTypeLabel( tx.tx_type ) ) }</td>

                <td>${ esc( formatTransactionDepartment( tx ) ) }</td>

                <td>
                    <span class="jsci-badge jsci-badge--${ esc( tx.status ) }">
                        ${ esc( tx.status ) }
                    </span>
                </td>

                <td style="font-size:11px;color:#50575e">
                    ${ esc( tx.created_at ) }
                </td>

                <td>

                    <button class="jsci-btn jsci-view-tx"
                            data-id="${ tx.id }"
                            data-include-voided="${ tx.status === 'voided' ? '1' : '0' }">
                        View
                    </button>

                    ${ jsciPrm.currentUser.transactionAccess?.edit && tx.status !== 'voided' ? `
                        <button class="jsci-btn jsci-btn--secondary jsci-edit-tx"
                                data-id="${ tx.id }">
                            Edit
                        </button>
                    ` : '' }

                    ${ jsciPrm.currentUser.transactionAccess?.void && tx.status !== 'voided' ? `
                        <button class="jsci-btn jsci-btn--danger jsci-delete-tx"
                                data-id="${ tx.id }">
                            Void
                        </button>
                    ` : '' }

                </td>

            </tr>

        ` ).join('');

        return `
            <table class="jsci-table">

                <thead>
                    <tr>

                        <th>TX number</th>
                        <th>Order</th>
                        <th>Type</th>
                        <th>Department</th>
                        <th>Status</th>
                        <th>Created</th>
                        <th>Actions</th>

                    </tr>
                </thead>

                <tbody>
                    ${ rows || `
                        <tr>
                            <td colspan="7">
                                No transactions found.
                            </td>
                        </tr>
                    `}
                </tbody>

            </table>
        `;
    }

    function renderTransactionPagination( currentPage, totalPages ) {
        const prevDisabled = currentPage <= 1 ? 'disabled' : '';
        const nextDisabled = currentPage >= totalPages ? 'disabled' : '';

        return `
            <div style="display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;margin-top:16px">
                <div style="font-size:13px;color:#50575e">
                    Page ${ esc( currentPage ) } of ${ esc( totalPages ) }
                </div>
                <div style="display:flex;gap:8px;flex-wrap:wrap">
                    <button
                        type="button"
                        class="jsci-btn jsci-btn--secondary jsci-transactions-page-btn"
                        data-page="${ currentPage - 1 }"
                        ${ prevDisabled }>
                        Previous
                    </button>
                    <button
                        type="button"
                        class="jsci-btn jsci-btn--secondary jsci-transactions-page-btn"
                        data-page="${ currentPage + 1 }"
                        ${ nextDisabled }>
                        Next
                    </button>
                </div>
            </div>
        `;
    }

    function formatTransactionDepartment( tx ) {
        const fromDepartment = tx.from_department_name || '';
        const toDepartment = tx.to_department_name || '';
        const type = String( tx.tx_type || '' );

        if ( type === 'send' || type === 'send_outside_factory' ) {
            return fromDepartment && toDepartment
                ? `${ fromDepartment } -> ${ toDepartment }`
                : fromDepartment || toDepartment || '';
        }

        return toDepartment || fromDepartment || '';
    }

    function renderTransactionPopup( tx ) {
        const isSendTransaction = String( tx.tx_type || '' ) === 'send';
        const isSendOutsideFactoryTransaction = String( tx.tx_type || '' ) === 'send_outside_factory';
        const isManualReceiveTransaction = String( tx.tx_type || '' ) === 'manual_receive';
        const isCuttingEntry = String( tx.entry_mode || '' ) === 'cutting';
        const isRejectedTransactionView = jsciTransactionsView === 'rejected_voided';
        const isVoidedTransactionView = jsciTransactionsView === 'voided';
        const totalQuantity = ( tx.items || [] ).reduce( ( total, item ) => total + ( parseInt( item.quantity, 10 ) || 0 ), 0 );
        const lineLabel = tx.line_number ? esc( tx.line_number ) : 'N/A';
        const createdAt = tx.created_at ? esc( tx.created_at ) : 'N/A';
        const confirmedAt = tx.confirmed_at ? esc( tx.confirmed_at ) : 'Pending';
        const voidedAt = tx.voided_at ? esc( tx.voided_at ) : 'N/A';
        const fromDepartment = tx.from_department_name ? esc( tx.from_department_name ) : 'N/A';
        const toDepartment = tx.to_department_name ? esc( tx.to_department_name ) : 'N/A';
        const createdByName = tx.created_by_name ? esc( tx.created_by_name ) : 'N/A';
        const confirmedByName = tx.confirmed_by_name ? esc( tx.confirmed_by_name ) : 'Pending';
        const declinedByName = tx.declined_by_name ? esc( tx.declined_by_name ) : 'Pending';
        const voidedByName = tx.voided_by_name ? esc( tx.voided_by_name ) : 'N/A';
        const currentDepartmentName = ( isSendTransaction || isSendOutsideFactoryTransaction ) ? fromDepartment : toDepartment;
        const processedAtLabel = isSendTransaction ? 'Processed at' : 'Confirmed';
        const sendDecisionLabel = tx.status === 'confirmed'
            ? 'Accepted by'
            : tx.status === 'voided'
                ? 'Rejected by'
                : 'Accepted / Rejected by';
        const sendDecisionName = tx.status === 'confirmed'
            ? confirmedByName
            : tx.status === 'voided'
                ? declinedByName
                : 'Pending';
        const actionByLabel = isRejectedTransactionView ? 'Rejected by' : 'Voided by';
        const actionTimeLabel = isRejectedTransactionView ? 'Reject time' : 'Void time';
        const actionByName = isRejectedTransactionView ? declinedByName : voidedByName;
        const printedAt = new Date().toLocaleString();
        const items = ( tx.items || [] ).map( item => `
            <tr>
                <td>${ esc( item.size_label ) }</td>
                <td>${ esc( item.quantity ) }</td>
            </tr>
        ` ).join( '' ) || `
            <tr>
                <td colspan="2">No size items found.</td>
            </tr>
        `;

        return `
            <div class="jsci-transaction-detail">
                <div class="jsci-transaction-header">
                    <div>
                        <div class="jsci-transaction-eyebrow">Transaction details</div>
                        <h2 class="jsci-transaction-title">${ esc( tx.tx_number ) }</h2>
                    </div>
                    <div class="jsci-transaction-header-actions">
                        <span class="jsci-badge jsci-badge--${ esc( tx.status ) } jsci-transaction-status">
                            ${ esc( tx.status ) }
                        </span>
                        <button class="jsci-btn jsci-print-tx-popup jsci-transaction-print" type="button">
                            Print
                        </button>
                        <button class="jsci-btn jsci-btn--secondary jsci-close-tx-popup jsci-transaction-close" type="button">
                            Close
                        </button>
                    </div>
                </div>

                <div class="jsci-transaction-meta">
                    <div class="jsci-transaction-meta-card">
                        <span>Order</span>
                        <strong>#${ esc( tx.order_id ) }</strong>
                    </div>
                    <div class="jsci-transaction-meta-card">
                        <span>Type</span>
                        <strong>${ esc( formatTransactionTypeLabel( tx.tx_type ) ) }</strong>
                    </div>
                    <div class="jsci-transaction-meta-card">
                        <span>${ isCuttingEntry ? 'Total pieces' : 'Total quantity' }</span>
                        <strong>${ esc( totalQuantity ) }</strong>
                    </div>
                    ${ isCuttingEntry ? `
                        <div class="jsci-transaction-meta-card">
                            <span>Fabric used</span>
                            <strong>${ esc( parseFloat( tx.fabric_used_kg || 0 ).toFixed( 3 ) ) } KG</strong>
                        </div>
                    ` : '' }
                </div>

                <div class="jsci-transaction-grid">
                    <div class="jsci-transaction-section">
                        <h3>Transaction info</h3>
                        <dl class="jsci-transaction-list">
                            <div><dt>Transaction ID</dt><dd>${ esc( tx.id ) }</dd></div>
                            <div><dt>Department</dt><dd>${ currentDepartmentName }</dd></div>
                            <div><dt>Created by</dt><dd>${ createdByName }</dd></div>
                            <div><dt>Created</dt><dd>${ createdAt }</dd></div>
                            <div><dt>${ processedAtLabel }</dt><dd>${ confirmedAt }</dd></div>
                            ${ isManualReceiveTransaction ? `<div><dt>Receive from</dt><dd>${ esc( tx.external_organization_name || 'N/A' ) }</dd></div>` : '' }
                            ${ tx.status === 'voided' && ! isSendTransaction ? `<div><dt>${ actionByLabel }</dt><dd>${ actionByName }</dd></div>` : '' }
                            ${ tx.status === 'voided' && ! isSendTransaction ? `<div><dt>${ actionTimeLabel }</dt><dd>${ voidedAt }</dd></div>` : '' }
                            <div><dt>Line</dt><dd>${ lineLabel }</dd></div>
                        </dl>
                    </div>

                    ${ isSendTransaction ? `
                        <div class="jsci-transaction-section">
                            <h3>Transfer details</h3>
                            <dl class="jsci-transaction-list">
                                <div><dt>Created by</dt><dd>${ createdByName }</dd></div>
                                <div><dt>${ sendDecisionLabel }</dt><dd>${ sendDecisionName }</dd></div>
                                ${ tx.status === 'voided' && isVoidedTransactionView ? `<div><dt>${ actionByLabel }</dt><dd>${ actionByName }</dd></div>` : '' }
                                ${ tx.status === 'voided' && ( isVoidedTransactionView || isRejectedTransactionView ) ? `<div><dt>${ actionTimeLabel }</dt><dd>${ voidedAt }</dd></div>` : '' }
                                <div><dt>From department</dt><dd>${ fromDepartment }</dd></div>
                                <div><dt>To department</dt><dd>${ toDepartment }</dd></div>
                            </dl>
                        </div>
                    ` : '' }

                    ${ isSendOutsideFactoryTransaction ? `
                        <div class="jsci-transaction-section">
                            <h3>Send outside factory details</h3>
                            <dl class="jsci-transaction-list">
                                <div><dt>Send Outside Purpose</dt><dd>${ esc( formatSendOutsidePurpose( tx.send_outside_purpose ) || 'N/A' ) }</dd></div>
                                <div><dt>External org. name</dt><dd>${ esc( tx.external_organization_name || 'N/A' ) }</dd></div>
                                <div><dt>From department</dt><dd>${ fromDepartment }</dd></div>
                            </dl>
                        </div>
                    ` : '' }
                    
                </div>                

                <div class="jsci-transaction-section jsci-transaction-table-wrap">
                    <h3>${ isCuttingEntry ? 'Fabric to Piece conversion quantities' : 'Size quantities' }</h3>
                    <table class="jsci-table jsci-transaction-items">
                        <thead>
                            <tr>
                                <th>Size</th>
                                <th>Quantity</th>
                            </tr>
                        </thead>
                        <tbody>${ items }</tbody>
                    </table>
                </div>
                ${ tx.notes ? `
                    <div class="jsci-transaction-section jsci-transaction-section--notes">
                        <h3>Notes</h3>
                        <div class="jsci-transaction-notes">
                            ${ esc( tx.notes ) }
                        </div>
                    </div>
                ` : '' }
                <div class="jsci-transaction-print-footer" aria-hidden="true">
                    <span>Printed: ${ esc( printedAt ) }</span>
                    <span class="jsci-transaction-print-page-number"></span>
                </div>
            </div>
        `;
    }

    function formatTransactionTypeLabel( type ) {
        const labels = {
            receive: 'Receive',
            manual_receive: 'Manual Receive',
            produce: 'Production',
            send: 'Send',
            send_outside_factory: 'Send Outside Factory',
            reject: 'Reject',
            return: 'Return',
        };

        return labels[ type ] || type || '';
    }

    function formatSendOutsidePurpose( value ) {
        const labels = {
            embroidery: 'Embroidery',
            dyeing: 'Dyeing',
            shipment: 'SHIPMENT',
        };

        return labels[ value ] || String( value || '' )
            .replace( /_/g, ' ' )
            .replace( /\b\w/g, letter => letter.toUpperCase() );
    }

    function ordersTable( orders ) {

        return `
            <table class="wp-list-table widefat striped">
                <thead>
                    <tr>
                        <th>Order No</th>
                        <th>Reference No</th>
                        <th>Buyer</th>
                        <th>Shipment Date</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>

                <tbody>
                    ${ orders.map( order => `
                        <tr>
                            <td>${ esc( order.order_number ) }</td>
                            <td>${ esc( order.reference_number || '-' ) }</td>
                            <td>${ esc( order.buyer_name || '-' ) }</td>
                            <td>${ esc( order.shipment_deadline || '-' ) }</td>
                            <td>${ esc( order.status ) }</td>

                            <td>
                                <div style="display:flex;gap:8px;flex-wrap:wrap">
                                    ${
                                        order.actions?.edit
                                            ? `
                                                <button
                                                    type="button"
                                                    class="button button-secondary edit-order-btn"
                                                    data-id="${ order.id }"
                                                    data-voided="${ order.deleted_at ? '1' : '0' }">
                                                    ${ esc( order.actions.edit?.label || 'Edit' ) }
                                                </button>
                                            `
                                            : ''
                                    }

                                    ${
                                        order.actions?.status || order.actions?.review
                                            ? `
                                                <button
                                                    type="button"
                                                    class="button button-primary jsci-open-order-entry-btn"
                                                    data-order-id="${ order.id }"
                                                    data-action="${ esc( order.actions.status?.action || order.actions.review?.action || '' ) }"
                                                    data-url="${ esc( order.actions.status?.url || order.actions.review?.url || '' ) }">
                                                    ${ esc( order.actions.status?.label || order.actions.review?.label || 'Change Status' ) }
                                                </button>
                                            `
                                            : ''
                                    }

                                    ${
                                        order.actions?.void
                                            ? `
                                                <button
                                                    type="button"
                                                    class="button button-secondary jsci-void-order-btn"
                                                    data-id="${ order.id }"
                                                    data-endpoint="${ esc( order.actions.void.endpoint || '' ) }">
                                                    Void
                                                </button>
                                            `
                                            : ''
                                    }
                                </div>
                            </td>
                        </tr>
                    ` ).join('') || `
                        <tr>
                            <td colspan="6">No orders found.</td>
                        </tr>
                    ` }
                </tbody>
            </table>
        `;
    }

    function renderOrdersPagination( currentPage, totalPages, total ) {
        const prevDisabled = currentPage <= 1 ? 'disabled' : '';
        const nextDisabled = currentPage >= totalPages ? 'disabled' : '';

        return `
            <div style="display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;margin-top:16px">
                <div style="font-size:13px;color:#50575e">
                    Showing 25 per page. Page ${ esc( currentPage ) } of ${ esc( totalPages ) } (${ esc( total ) } orders)
                </div>
                <div style="display:flex;gap:8px;flex-wrap:wrap">
                    <button
                        type="button"
                        class="jsci-btn jsci-btn--secondary jsci-orders-page-btn"
                        data-page="${ currentPage - 1 }"
                        ${ prevDisabled }>
                        Previous
                    </button>
                    <button
                        type="button"
                        class="jsci-btn jsci-btn--secondary jsci-orders-page-btn"
                        data-page="${ currentPage + 1 }"
                        ${ nextDisabled }>
                        Next
                    </button>
                </div>
            </div>
        `;
    }

    function statCard( label, value ) {
        return `
            <div class="jsci-card">
                <div class="jsci-card__title">${ esc( label ) }</div>
                <div class="jsci-card__value">${ value }</div>
            </div>
        `;
    }

    function loader() {
        return '<div class="jsci-loader"><div class="jsci-spinner"></div> Loading…</div>';
    }

    function notice( msg, type = 'info' ) {
        return `<div class="jsci-notice jsci-notice--${ type }">${ esc( msg ) }</div>`;
    }

    function requestPasswordConfirmation( title, message ) {
        return new Promise(resolve => {
            const overlay = document.createElement('div');
            overlay.style.cssText = `
                position:fixed;
                inset:0;
                z-index:100000;
                display:flex;
                align-items:center;
                justify-content:center;
                padding:20px;
                background:rgba(15,23,42,.46);
            `;

            overlay.innerHTML = `
                <div role="dialog" aria-modal="true" aria-labelledby="jsci-password-confirm-title" style="
                    width:min(420px,100%);
                    background:#fff;
                    border-radius:8px;
                    box-shadow:0 24px 70px rgba(15,23,42,.22);
                    padding:22px;
                ">
                    <h2 id="jsci-password-confirm-title" style="margin:0 0 8px;font-size:20px;line-height:1.3">
                        ${ esc( title ) }
                    </h2>
                    <p style="margin:0 0 16px;color:#475569;line-height:1.5">
                        ${ esc( message ) }
                    </p>
                    <input
                        type="password"
                        id="jsci-password-confirm-input"
                        autocomplete="current-password"
                        style="width:100%;min-height:40px;margin-bottom:16px">
                    <div style="display:flex;justify-content:flex-end;gap:8px;flex-wrap:wrap">
                        <button type="button" class="button button-secondary" id="jsci-password-confirm-cancel">
                            Cancel
                        </button>
                        <button type="button" class="button button-primary" id="jsci-password-confirm-submit">
                            Confirm
                        </button>
                    </div>
                </div>
            `;

            document.body.appendChild(overlay);

            const input = overlay.querySelector('#jsci-password-confirm-input');
            const close = value => {
                overlay.remove();
                resolve(value);
            };

            overlay.querySelector('#jsci-password-confirm-cancel')
                ?.addEventListener('click', () => close(''));

            overlay.querySelector('#jsci-password-confirm-submit')
                ?.addEventListener('click', () => close(input?.value || ''));

            overlay.addEventListener('keydown', event => {
                if ( event.key === 'Escape' ) {
                    close('');
                }

                if ( event.key === 'Enter' ) {
                    event.preventDefault();
                    close(input?.value || '');
                }
            });

            input?.focus();
        });
    }

    function esc( str ) {
        return String( str ?? '' )
            .replace( /&/g, '&amp;' )
            .replace( /</g, '&lt;' )
            .replace( />/g, '&gt;' )
            .replace( /"/g, '&quot;' );
    }

} )();
