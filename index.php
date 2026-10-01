<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>LPF Pullman - Monitor de Posición y Velocidad GPS</title>
    <!-- Google Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <!-- FontAwesome Icons -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
    <style>
        :root {
            --bg-body: #0b0f19;
            --bg-card: #111827;
            --bg-card-hover: #1f2937;
            --border-color: #1f293d;
            --text-main: #f3f4f6;
            --text-muted: #9ca3af;
            --primary: #3b82f6;
            --primary-hover: #2563eb;
            --success: #10b981;
            --warning: #f59e0b;
            --danger: #ef4444;
            --idle: #6b7280;
        }

        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

        body {
            font-family: 'Inter', sans-serif;
            background-color: var(--bg-body);
            color: var(--text-main);
            line-height: 1.5;
            padding: 24px;
            min-height: 100vh;
        }

        .container {
            max-width: 1400px;
            margin: 0 auto;
        }

        /* Header */
        header {
            display: flex;
            flex-wrap: wrap;
            justify-content: space-between;
            align-items: center;
            background: var(--bg-card);
            border: 1px solid var(--border-color);
            padding: 20px 24px;
            border-radius: 16px;
            margin-bottom: 24px;
            box-shadow: 0 10px 25px -5px rgba(0, 0, 0, 0.4);
        }

        .brand {
            display: flex;
            align-items: center;
            gap: 16px;
        }

        .brand-icon {
            background: linear-gradient(135deg, var(--primary), #1d4ed8);
            width: 48px;
            height: 48px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 24px;
            color: white;
            box-shadow: 0 4px 12px rgba(59, 130, 246, 0.4);
        }

        .brand-text h1 {
            font-size: 22px;
            font-weight: 700;
            letter-spacing: -0.5px;
        }

        .brand-text p {
            font-size: 13px;
            color: var(--text-muted);
        }

        .controls {
            display: flex;
            align-items: center;
            gap: 12px;
            flex-wrap: wrap;
        }

        .select-account, .select-auto-refresh {
            background: #1e293b;
            color: var(--text-main);
            border: 1px solid var(--border-color);
            padding: 10px 16px;
            border-radius: 10px;
            font-size: 14px;
            outline: none;
            cursor: pointer;
            transition: all 0.2s;
        }

        .select-account:hover, .select-auto-refresh:hover {
            border-color: var(--primary);
        }

        .btn {
            background: var(--primary);
            color: white;
            border: none;
            padding: 10px 18px;
            border-radius: 10px;
            font-weight: 600;
            font-size: 14px;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            transition: all 0.2s;
            box-shadow: 0 4px 12px rgba(59, 130, 246, 0.3);
        }

        .btn:hover {
            background: var(--primary-hover);
            transform: translateY(-1px);
        }

        .btn:active {
            transform: translateY(0);
        }

        /* Metrics Bar */
        .metrics-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
            gap: 16px;
            margin-bottom: 24px;
        }

        .metric-card {
            background: var(--bg-card);
            border: 1px solid var(--border-color);
            padding: 20px;
            border-radius: 16px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            transition: transform 0.2s;
        }

        .metric-card:hover {
            transform: translateY(-2px);
        }

        .metric-info h3 {
            font-size: 13px;
            font-weight: 500;
            color: var(--text-muted);
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .metric-info .value {
            font-size: 28px;
            font-weight: 700;
            margin-top: 4px;
        }

        .metric-icon {
            width: 44px;
            height: 44px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 20px;
        }

        .icon-total { background: rgba(59, 130, 246, 0.15); color: var(--primary); }
        .icon-moving { background: rgba(16, 185, 129, 0.15); color: var(--success); }
        .icon-stopped { background: rgba(245, 158, 11, 0.15); color: var(--warning); }
        .icon-max-speed { background: rgba(239, 68, 68, 0.15); color: var(--danger); }

        /* Filter Toolbar */
        .toolbar {
            background: var(--bg-card);
            border: 1px solid var(--border-color);
            padding: 16px 20px;
            border-radius: 16px;
            margin-bottom: 24px;
            display: flex;
            flex-wrap: wrap;
            justify-content: space-between;
            align-items: center;
            gap: 16px;
        }

        .search-box {
            position: relative;
            flex: 1;
            min-width: 260px;
        }

        .search-box i {
            position: absolute;
            left: 14px;
            top: 50%;
            transform: translateY(-50%);
            color: var(--text-muted);
        }

        .search-box input {
            width: 100%;
            background: #1e293b;
            border: 1px solid var(--border-color);
            padding: 10px 16px 10px 42px;
            border-radius: 10px;
            color: var(--text-main);
            font-size: 14px;
            outline: none;
        }

        .search-box input:focus {
            border-color: var(--primary);
        }

        .filter-tabs {
            display: flex;
            gap: 8px;
            background: #1e293b;
            padding: 4px;
            border-radius: 10px;
        }

        .tab-btn {
            background: transparent;
            border: none;
            color: var(--text-muted);
            padding: 8px 14px;
            font-size: 13px;
            font-weight: 500;
            border-radius: 8px;
            cursor: pointer;
            transition: all 0.2s;
        }

        .tab-btn.active {
            background: var(--primary);
            color: white;
        }

        /* Table Container */
        .table-card {
            background: var(--bg-card);
            border: 1px solid var(--border-color);
            border-radius: 16px;
            overflow: hidden;
            box-shadow: 0 10px 25px -5px rgba(0, 0, 0, 0.4);
        }

        table {
            width: 100%;
            border-collapse: collapse;
            text-align: left;
        }

        th {
            background: #1a2234;
            padding: 16px 20px;
            font-size: 12px;
            font-weight: 600;
            color: var(--text-muted);
            text-transform: uppercase;
            letter-spacing: 0.5px;
            border-bottom: 1px solid var(--border-color);
        }

        td {
            padding: 16px 20px;
            font-size: 14px;
            border-bottom: 1px solid var(--border-color);
        }

        tr:last-child td {
            border-bottom: none;
        }

        tr:hover td {
            background: var(--bg-card-hover);
        }

        /* Badges & Status */
        .badge-vehicle {
            font-weight: 700;
            font-size: 15px;
            color: #ffffff;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .badge-speed {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 6px 12px;
            border-radius: 20px;
            font-weight: 700;
            font-size: 14px;
        }

        .speed-stopped {
            background: rgba(107, 114, 128, 0.15);
            color: #9ca3af;
            border: 1px solid rgba(107, 114, 128, 0.3);
        }

        .speed-normal {
            background: rgba(16, 185, 129, 0.15);
            color: #34d399;
            border: 1px solid rgba(16, 185, 129, 0.3);
        }

        .speed-warning {
            background: rgba(245, 158, 11, 0.15);
            color: #fbbf24;
            border: 1px solid rgba(245, 158, 11, 0.3);
        }

        .speed-danger {
            background: rgba(239, 68, 68, 0.15);
            color: #f87171;
            border: 1px solid rgba(239, 68, 68, 0.3);
            animation: pulse-border 1.5s infinite;
        }

        @keyframes pulse-border {
            0% { box-shadow: 0 0 0 0 rgba(239, 68, 68, 0.4); }
            70% { box-shadow: 0 0 0 8px rgba(239, 68, 68, 0); }
            100% { box-shadow: 0 0 0 0 rgba(239, 68, 68, 0); }
        }

        .badge-status {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 4px 10px;
            border-radius: 8px;
            font-size: 12px;
            font-weight: 600;
            text-transform: capitalize;
        }

        .status-active, .status-online, .status-idle {
            background: rgba(16, 185, 129, 0.1);
            color: #34d399;
        }

        .status-offline {
            background: rgba(239, 68, 68, 0.1);
            color: #f87171;
        }

        .link-maps {
            color: var(--primary);
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 4px;
            font-size: 13px;
            font-weight: 500;
        }

        .link-maps:hover {
            text-decoration: underline;
        }

        /* Loading & Empty State */
        .loading-state, .empty-state {
            padding: 60px;
            text-align: center;
            color: var(--text-muted);
        }

        .spinner {
            font-size: 32px;
            color: var(--primary);
            margin-bottom: 12px;
            animation: spin 1s linear infinite;
        }

        @keyframes spin {
            from { transform: rotate(0deg); }
            to { transform: rotate(3600deg); }
        }

        .last-update-tag {
            font-size: 12px;
            color: var(--text-muted);
        }
    </style>
</head>
<body>

    <div class="container">
        <!-- Header -->
        <header>
            <div class="brand">
                <div class="brand-icon">
                    <i class="fa-solid fa-bus"></i>
                </div>
                <div class="brand-text">
                    <h1>LPF Pullman - Monitor GPS</h1>
                    <p>Posición y velocidad en tiempo real | MasGPS API v2</p>
                </div>
            </div>
            
            <div class="controls">
                <select id="accountSelect" class="select-account">
                    <option value="pullman">Cuenta: Pullman Principal</option>
                    <option value="bi_pullman">Cuenta: BI Pullman (Tandem)</option>
                    <option value="araucania">Cuenta: Monitoreo Araucanía Sur</option>
                    <option value="ventanas">Cuenta: Minera Ventanas</option>
                    <option value="lascondes">Cuenta: Monitoreo Las Condes</option>
                    <option value="integral">Cuenta: Proyecto Integral</option>
                </select>

                <select id="autoRefreshSelect" class="select-auto-refresh">
                    <option value="0">Auto-refresco: Desactivado</option>
                    <option value="15">Auto-refresco: 15 seg</option>
                    <option value="30" selected>Auto-refresco: 30 seg</option>
                    <option value="60">Auto-refresco: 60 seg</option>
                </select>

                <button id="refreshBtn" class="btn">
                    <i class="fa-solid fa-rotate"></i>
                    <span>Actualizar Ahora</span>
                </button>
            </div>
        </header>

        <!-- KPI Metrics -->
        <div class="metrics-grid">
            <div class="metric-card">
                <div class="metric-info">
                    <h3>Total Móviles</h3>
                    <div id="metricTotal" class="value">0</div>
                </div>
                <div class="metric-icon icon-total">
                    <i class="fa-solid fa-truck-monster"></i>
                </div>
            </div>

            <div class="metric-card">
                <div class="metric-info">
                    <h3>En Movimiento</h3>
                    <div id="metricMoving" class="value">0</div>
                </div>
                <div class="metric-icon icon-moving">
                    <i class="fa-solid fa-gauge-high"></i>
                </div>
            </div>

            <div class="metric-card">
                <div class="metric-info">
                    <h3>Detenidos</h3>
                    <div id="metricStopped" class="value">0</div>
                </div>
                <div class="metric-icon icon-stopped">
                    <i class="fa-solid fa-circle-pause"></i>
                </div>
            </div>

            <div class="metric-card">
                <div class="metric-info">
                    <h3>Velocidad Máxima</h3>
                    <div id="metricMaxSpeed" class="value">0 km/h</div>
                </div>
                <div class="metric-icon icon-max-speed">
                    <i class="fa-solid fa-bolt"></i>
                </div>
            </div>
        </div>

        <!-- Toolbar Filters -->
        <div class="toolbar">
            <div class="search-box">
                <i class="fa-solid fa-magnifying-glass"></i>
                <input type="text" id="searchInput" placeholder="Buscar por Patente, Vehículo o IMEI...">
            </div>

            <div class="filter-tabs">
                <button class="tab-btn active" data-filter="all">Todos</button>
                <button class="tab-btn" data-filter="moving">En Movimiento 🟢</button>
                <button class="tab-btn" data-filter="stopped">Detenidos ⏸️</button>
                <button class="tab-btn" data-filter="offline">Desconectados 🔴</button>
            </div>
        </div>

        <!-- Data Table -->
        <div class="table-card">
            <table>
                <thead>
                    <tr>
                        <th>Patente / Vehículo</th>
                        <th>Velocidad Actual</th>
                        <th>Posición (Lat, Lng)</th>
                        <th>Estado Conexión</th>
                        <th>IMEI / Dispositivo</th>
                        <th>Última Actualización</th>
                    </tr>
                </thead>
                <tbody id="trackerTableBody">
                    <tr>
                        <td colspan="6">
                            <div class="loading-state">
                                <i class="fa-solid fa-circle-notch spinner"></i>
                                <p>Cargando posiciones y velocidades en tiempo real...</p>
                            </div>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>

    <script>
        let allTrackersData = [];
        let currentFilter = 'all';
        let autoRefreshTimer = null;
        let countdownTimer = null;

        document.addEventListener('DOMContentLoaded', () => {
            fetchTrackersData();

            document.getElementById('refreshBtn').addEventListener('click', () => {
                fetchTrackersData();
            });

            document.getElementById('accountSelect').addEventListener('change', () => {
                fetchTrackersData();
            });

            document.getElementById('searchInput').addEventListener('input', applyFilters);

            document.querySelectorAll('.tab-btn').forEach(btn => {
                btn.addEventListener('click', (e) => {
                    document.querySelectorAll('.tab-btn').forEach(b => b.classList.remove('active'));
                    e.target.classList.add('active');
                    currentFilter = e.target.getAttribute('data-filter');
                    applyFilters();
                });
            });

            document.getElementById('autoRefreshSelect').addEventListener('change', setupAutoRefresh);
            setupAutoRefresh();
        });

        async function fetchTrackersData() {
            const refreshBtn = document.getElementById('refreshBtn');
            const account = document.getElementById('accountSelect').value;
            const tableBody = document.getElementById('trackerTableBody');

            refreshBtn.disabled = true;
            refreshBtn.querySelector('i').classList.add('fa-spin');

            try {
                const response = await fetch(`api.php?account=${account}`);
                const result = await response.json();

                if (!result.success) {
                    tableBody.innerHTML = `
                        <tr>
                            <td colspan="6">
                                <div class="empty-state">
                                    <i class="fa-solid fa-triangle-exclamation" style="font-size: 36px; color: var(--danger); margin-bottom: 12px;"></i>
                                    <p style="color: var(--danger); font-weight: 600;">${result.error || 'Error al conectar con la API'}</p>
                                </div>
                            </td>
                        </tr>
                    `;
                    return;
                }

                allTrackersData = result.data || [];
                updateMetrics(result.summary, allTrackersData);
                applyFilters();

            } catch (error) {
                tableBody.innerHTML = `
                    <tr>
                        <td colspan="6">
                            <div class="empty-state">
                                <i class="fa-solid fa-circle-xmark" style="font-size: 36px; color: var(--danger); margin-bottom: 12px;"></i>
                                <p style="color: var(--danger);">Error de red al consultar el servidor local.</p>
                            </div>
                        </td>
                    </tr>
                `;
            } finally {
                refreshBtn.disabled = false;
                refreshBtn.querySelector('i').classList.remove('fa-spin');
            }
        }

        function updateMetrics(summary, data) {
            document.getElementById('metricTotal').textContent = summary.total || 0;
            document.getElementById('metricMoving').textContent = summary.moving || 0;
            document.getElementById('metricStopped').textContent = summary.stopped || 0;

            let maxSpeed = 0;
            data.forEach(item => {
                if (item.speed > maxSpeed) {
                    maxSpeed = item.speed;
                }
            });
            document.getElementById('metricMaxSpeed').textContent = `${maxSpeed} km/h`;
        }

        function applyFilters() {
            const searchTerm = document.getElementById('searchInput').value.toLowerCase();
            const tableBody = document.getElementById('trackerTableBody');

            const filtered = allTrackersData.filter(item => {
                // Filtro de búsqueda
                const matchesSearch = item.label.toLowerCase().includes(searchTerm) ||
                                      String(item.imei).toLowerCase().includes(searchTerm) ||
                                      String(item.id).includes(searchTerm);

                // Filtro por tab
                let matchesTab = true;
                if (currentFilter === 'moving') {
                    matchesTab = item.speed > 0;
                } else if (currentFilter === 'stopped') {
                    matchesTab = item.speed === 0;
                } else if (currentFilter === 'offline') {
                    matchesTab = item.connection_status.toLowerCase() === 'offline';
                }

                return matchesSearch && matchesTab;
            });

            if (filtered.length === 0) {
                tableBody.innerHTML = `
                    <tr>
                        <td colspan="6">
                            <div class="empty-state">
                                <i class="fa-solid fa-magnifying-glass" style="font-size: 32px; margin-bottom: 12px;"></i>
                                <p>No se encontraron móviles con los criterios seleccionados.</p>
                            </div>
                        </td>
                    </tr>
                `;
                return;
            }

            // Renderizar tabla
            tableBody.innerHTML = filtered.map(item => {
                // Badge de velocidad
                let speedClass = 'speed-stopped';
                let speedIcon = '<i class="fa-solid fa-stop"></i>';
                if (item.speed > 80) {
                    speedClass = 'speed-danger';
                    speedIcon = '<i class="fa-solid fa-triangle-exclamation"></i>';
                } else if (item.speed > 50) {
                    speedClass = 'speed-warning';
                    speedIcon = '<i class="fa-solid fa-bolt"></i>';
                } else if (item.speed > 0) {
                    speedClass = 'speed-normal';
                    speedIcon = '<i class="fa-solid fa-arrow-up-right-dots"></i>';
                }

                // Posición & enlace a Google Maps
                let posHtml = '<span style="color: var(--text-muted);">Sin GPS</span>';
                if (item.lat && item.lng) {
                    const mapsUrl = `https://www.google.com/maps?q=${item.lat},${item.lng}`;
                    posHtml = `
                        <a href="${mapsUrl}" target="_blank" class="link-maps">
                            <i class="fa-solid fa-location-dot"></i>
                            ${item.lat.toFixed(5)}, ${item.lng.toFixed(5)}
                        </a>
                    `;
                }

                // Badge estado conexión
                const statusClass = item.connection_status === 'active' || item.connection_status === 'online' || item.connection_status === 'idle'
                    ? 'status-online'
                    : 'status-offline';

                return `
                    <tr>
                        <td>
                            <div class="badge-vehicle">
                                <i class="fa-solid fa-bus-simple" style="color: var(--primary);"></i>
                                ${escapeHtml(item.label)}
                            </div>
                        </td>
                        <td>
                            <span class="badge-speed ${speedClass}">
                                ${speedIcon}
                                ${item.speed} km/h
                            </span>
                        </td>
                        <td>${posHtml}</td>
                        <td>
                            <span class="badge-status ${statusClass}">
                                ${item.connection_status}
                            </span>
                        </td>
                        <td style="color: var(--text-muted); font-family: monospace;">${escapeHtml(String(item.imei))}</td>
                        <td class="last-update-tag">${escapeHtml(item.last_update)}</td>
                    </tr>
                `;
            }).join('');
        }

        function setupAutoRefresh() {
            if (autoRefreshTimer) clearInterval(autoRefreshTimer);
            const seconds = parseInt(document.getElementById('autoRefreshSelect').value, 10);
            if (seconds > 0) {
                autoRefreshTimer = setInterval(() => {
                    fetchTrackersData();
                }, seconds * 1000);
            }
        }

        function escapeHtml(text) {
            return String(text).replace(/[&<>"']/g, function(m) {
                return {
                    '&': '&amp;',
                    '<': '&lt;',
                    '>': '&gt;',
                    '"': '&quot;',
                    "'": '&#039;'
                }[m];
            });
        }
    </script>
</body>
</html>
