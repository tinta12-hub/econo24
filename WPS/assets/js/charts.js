/**
 * 24/7 - Pure SVG Interactive Charts Engine
 */

const Charts = {
    /**
     * Render the 24-Hour Operations Pulse Chart (Hours 00:00 to 23:00)
     */
    render24HourPulseChart(containerId, hourlyData) {
        const container = document.getElementById(containerId);
        if (!container || !hourlyData || hourlyData.length === 0) return;

        const width = container.clientWidth || 700;
        const height = container.clientHeight || 260;
        const padding = { top: 20, right: 20, bottom: 35, left: 40 };

        const innerWidth = width - padding.left - padding.right;
        const innerHeight = height - padding.top - padding.bottom;

        // Calculate max value across series
        let maxVal = 5;
        hourlyData.forEach(d => {
            const sum = Math.max(d.attendance_clockins || 0, d.tasks_completed || 0, d.customer_tickets || 0);
            if (sum > maxVal) maxVal = sum;
        });
        maxVal = Math.ceil(maxVal * 1.25);

        // Coordinates helpers
        const getX = (index) => padding.left + (index / (hourlyData.length - 1)) * innerWidth;
        const getY = (val) => padding.top + innerHeight - (val / maxVal) * innerHeight;

        // Build SVG paths for line series
        const makePath = (key) => {
            return hourlyData.map((d, i) => `${i === 0 ? 'M' : 'L'} ${getX(i).toFixed(1)} ${getY(d[key] || 0).toFixed(1)}`).join(' ');
        };

        const makeArea = (key) => {
            const line = makePath(key);
            const firstX = getX(0).toFixed(1);
            const lastX = getX(hourlyData.length - 1).toFixed(1);
            const baseY = (padding.top + innerHeight).toFixed(1);
            return `${line} L ${lastX} ${baseY} L ${firstX} ${baseY} Z`;
        };

        const attPath = makePath('attendance_clockins');
        const attArea = makeArea('attendance_clockins');
        const taskPath = makePath('tasks_completed');
        const tickPath = makePath('customer_tickets');

        // Grid lines (horizontal)
        let gridLines = '';
        const gridSteps = 4;
        for (let i = 0; i <= gridSteps; i++) {
            const val = Math.round((maxVal / gridSteps) * i);
            const y = getY(val);
            gridLines += `
                <line x1="${padding.left}" y1="${y}" x2="${width - padding.right}" y2="${y}" stroke="rgba(255,255,255,0.06)" stroke-dasharray="3 3" />
                <text x="${padding.left - 8}" y="${y + 4}" fill="#64748b" font-size="10" font-family="'JetBrains Mono', monospace" text-anchor="end">${val}</text>
            `;
        }

        // Night Shift Shading (Hours 22:00 to 24:00, and 00:00 to 06:00)
        const x0 = getX(0);
        const x6 = getX(6);
        const x22 = getX(22);
        const x23 = getX(23);

        const nightShading = `
            <rect x="${x0}" y="${padding.top}" width="${x6 - x0}" height="${innerHeight}" fill="rgba(139, 92, 246, 0.08)" rx="4" />
            <text x="${(x0 + x6) / 2}" y="${padding.top + 16}" fill="#a855f7" font-size="10" font-weight="700" text-anchor="middle">🌙 NIGHT / GRAVEYARD</text>
            <rect x="${x22}" y="${padding.top}" width="${x23 - x22 + (innerWidth / 24)}" height="${innerHeight}" fill="rgba(139, 92, 246, 0.08)" rx="4" />
        `;

        // X-Axis hour labels (Every 2 hours)
        let xLabels = '';
        hourlyData.forEach((d, i) => {
            if (i % 2 === 0 || i === 23) {
                const x = getX(i);
                xLabels += `<text x="${x}" y="${height - 10}" fill="#94a3b8" font-size="10" font-family="'JetBrains Mono', monospace" text-anchor="middle">${d.hour}</text>`;
            }
        });

        // Hover points
        let hoverCircles = '';
        hourlyData.forEach((d, i) => {
            const x = getX(i);
            const yAtt = getY(d.attendance_clockins || 0);
            const yTask = getY(d.tasks_completed || 0);
            const yTick = getY(d.customer_tickets || 0);
            hoverCircles += `
                <g class="chart-point-group" data-hour="${d.hour}" data-att="${d.attendance_clockins || 0}" data-task="${d.tasks_completed || 0}" data-tick="${d.customer_tickets || 0}">
                    <line x1="${x}" y1="${padding.top}" x2="${x}" y2="${padding.top + innerHeight}" stroke="rgba(255,255,255,0.15)" stroke-width="1" class="chart-hover-line" style="opacity:0;" />
                    <circle cx="${x}" cy="${yAtt}" r="4" fill="#06b6d4" stroke="#0a0e17" stroke-width="2" />
                    <circle cx="${x}" cy="${yTask}" r="4" fill="#10b981" stroke="#0a0e17" stroke-width="2" />
                    <circle cx="${x}" cy="${yTick}" r="4" fill="#f59e0b" stroke="#0a0e17" stroke-width="2" />
                    <rect x="${x - (innerWidth / 48)}" y="${padding.top}" width="${innerWidth / 24}" height="${innerHeight}" fill="transparent" style="cursor:pointer;" />
                </g>
            `;
        });

        container.innerHTML = `
            <svg width="100%" height="100%" viewBox="0 0 ${width} ${height}" style="overflow:visible;">
                <defs>
                    <linearGradient id="attGrad" x1="0" y1="0" x2="0" y2="1">
                        <stop offset="0%" stop-color="#06b6d4" stop-opacity="0.3"/>
                        <stop offset="100%" stop-color="#06b6d4" stop-opacity="0.0"/>
                    </linearGradient>
                </defs>
                ${nightShading}
                ${gridLines}
                <path d="${attArea}" fill="url(#attGrad)" />
                <path d="${attPath}" fill="none" stroke="#06b6d4" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" />
                <path d="${taskPath}" fill="none" stroke="#10b981" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" />
                <path d="${tickPath}" fill="none" stroke="#f59e0b" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" stroke-dasharray="4 2" />
                ${xLabels}
                ${hoverCircles}
            </svg>
            <div id="chart-tooltip" style="position:absolute; display:none; background:#1e293b; border:1px solid #334155; border-radius:8px; padding:8px 12px; font-size:12px; pointer-events:none; box-shadow:0 8px 16px rgba(0,0,0,0.4); z-index:10;"></div>
        `;

        // Tooltip interaction
        const tooltip = container.querySelector('#chart-tooltip');
        const groups = container.querySelectorAll('.chart-point-group');

        groups.forEach(g => {
            g.addEventListener('mouseenter', (e) => {
                const hour = g.dataset.hour;
                const att = g.dataset.att;
                const task = g.dataset.task;
                const tick = g.dataset.tick;
                const line = g.querySelector('.chart-hover-line');
                if (line) line.style.opacity = '1';

                tooltip.innerHTML = `
                    <div style="font-weight:700; color:#f8fafc; margin-bottom:4px; font-family:'JetBrains Mono';">${hour} Operational Activity</div>
                    <div style="color:#06b6d4;">● Clock-ins: <b>${att}</b></div>
                    <div style="color:#10b981;">● Tasks Done: <b>${task}</b></div>
                    <div style="color:#f59e0b;">● Tickets: <b>${tick}</b></div>
                `;
                tooltip.style.display = 'block';
            });

            g.addEventListener('mousemove', (e) => {
                const rect = container.getBoundingClientRect();
                const x = e.clientX - rect.left + 15;
                const y = e.clientY - rect.top - 20;
                tooltip.style.left = `${x}px`;
                tooltip.style.top = `${y}px`;
            });

            g.addEventListener('mouseleave', () => {
                const line = g.querySelector('.chart-hover-line');
                if (line) line.style.opacity = '0';
                tooltip.style.display = 'none';
            });
        });
    },

    /**
     * Render Day vs Night Operations Doughnut
     */
    renderDayNightDoughnut(containerId, laborData) {
        const container = document.getElementById(containerId);
        if (!container || !laborData) return;

        const regular = laborData.regular_hours || 1;
        const night = laborData.night_hours || 0.5;
        const overtime = laborData.overtime_hours || 0.2;
        const total = regular + night + overtime;

        const p1 = (regular / total) * 100;
        const p2 = (night / total) * 100;
        const p3 = (overtime / total) * 100;

        container.innerHTML = `
            <div style="display:flex; align-items:center; justify-content:space-around; gap:1.5rem; flex-wrap:wrap; padding:1rem 0;">
                <div style="position:relative; width:150px; height:150px;">
                    <svg viewBox="0 0 36 36" style="width:100%; height:100%; transform:rotate(-90deg);">
                        <circle cx="18" cy="18" r="15.915" fill="transparent" stroke="rgba(255,255,255,0.06)" stroke-width="4"></circle>
                        <circle cx="18" cy="18" r="15.915" fill="transparent" stroke="#0ea5e9" stroke-width="4" stroke-dasharray="${p1} ${100 - p1}" stroke-dashoffset="0"></circle>
                        <circle cx="18" cy="18" r="15.915" fill="transparent" stroke="#8b5cf6" stroke-width="4" stroke-dasharray="${p2} ${100 - p2}" stroke-dashoffset="-${p1}"></circle>
                        <circle cx="18" cy="18" r="15.915" fill="transparent" stroke="#f59e0b" stroke-width="4" stroke-dasharray="${p3} ${100 - p3}" stroke-dashoffset="-${p1 + p2}"></circle>
                    </svg>
                    <div style="position:absolute; top:50%; left:50%; transform:translate(-50%,-50%); text-align:center;">
                        <span style="font-family:'JetBrains Mono'; font-weight:800; font-size:1.35rem; color:#f8fafc;">${total.toFixed(0)}h</span>
                        <div style="font-size:0.65rem; color:#94a3b8; text-transform:uppercase;">Total Hours</div>
                    </div>
                </div>
                <div style="display:flex; flex-direction:column; gap:0.65rem; min-width:180px;">
                    <div style="display:flex; align-items:center; justify-content:space-between; font-size:0.85rem;">
                        <span style="display:flex; align-items:center; gap:6px;"><span style="width:10px; height:10px; border-radius:50%; background:#0ea5e9;"></span> Regular Day Hours:</span>
                        <b style="font-family:'JetBrains Mono';">${regular.toFixed(1)}h</b>
                    </div>
                    <div style="display:flex; align-items:center; justify-content:space-between; font-size:0.85rem;">
                        <span style="display:flex; align-items:center; gap:6px;"><span style="width:10px; height:10px; border-radius:50%; background:#8b5cf6;"></span> Overnight Night Hours:</span>
                        <b style="font-family:'JetBrains Mono'; color:#c084fc;">${night.toFixed(1)}h</b>
                    </div>
                    <div style="display:flex; align-items:center; justify-content:space-between; font-size:0.85rem;">
                        <span style="display:flex; align-items:center; gap:6px;"><span style="width:10px; height:10px; border-radius:50%; background:#f59e0b;"></span> Overtime Hours:</span>
                        <b style="font-family:'JetBrains Mono'; color:#f59e0b;">${overtime.toFixed(1)}h</b>
                    </div>
                    <div style="margin-top:0.4rem; padding-top:0.5rem; border-top:1px solid rgba(255,255,255,0.08); display:flex; justify-content:space-between; font-size:0.88rem;">
                        <span>Est. Labor Cost:</span>
                        <b style="font-family:'JetBrains Mono'; color:#10b981;">${laborData.currency || 'ZMW'}${Number(laborData.total_labor_cost || 0).toLocaleString()}</b>
                    </div>
                </div>
            </div>
        `;
    }
};
