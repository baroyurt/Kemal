// assets/js/map.js - TÜM ÖZELLİKLER ÇALIŞIYOR

document.addEventListener('DOMContentLoaded', function() {
    console.log('Map.js yüklendi - Tüm özellikler');
    
    // Global değişkenler
    window.selectedMachines = [];
    window.groupsData = {};

    // Track currently active floor ('all', '0', '1', '2', '3')
    let currentFloor = 'all';

    // Zoom / pan state
    let mapScale = 1;
    let mapTranslateX = 0;
    let mapTranslateY = 0;
    const MAP_SCALE_MIN = 0.1;
    const MAP_SCALE_MAX = 10;
    const PAN_CLICK_THRESHOLD = 5; // Pan hareketi ile click ayrımı için piksel eşiği

    // Harita pan state (sol tuş + boş alan sürükleme)
    let isPanning = false;
    let panMoved = false; // Pan sırasında threshold aşıldı mı?
    let panStartX = 0;
    let panStartY = 0;
    let panStartTX = 0;
    let panStartTY = 0;

    // Sürükleme değişkenleri
    let isMoving = false;
    let currentMachine = null;
    let offsetX = 0;
    let offsetY = 0;
    let initialPositions = [];

    // Pending drag (threshold before starting drag)
    let pendingDragMachine = null;
    let pendingDragStartX = 0;
    let pendingDragStartY = 0;
    let pendingDragCtrl = false;
    // Flag to suppress click selection after a drag ends
    let suppressNextClick = false;

    // Group-frame drag (separate delta-based system to avoid coordinate confusion)
    let groupDragActive = false;
    let groupDragStartX = 0;
    let groupDragStartY = 0;
    let groupDragInitialPositions = [];
    // Pending group drag state (5px threshold before activating, same as machine drag)
    let pendingGroupDragGid = null;
    let pendingGroupDragMouseX = 0;
    let pendingGroupDragMouseY = 0;

    // Sürükleme ile seçim değişkenleri
    let selectionStart = null;
    let selectionBox = null;
    let selectionDragStarted = false;

    // Tooltip değişkenleri
    let tooltipTimer = null;
    let activeTooltip = null;
    
    // Makineleri al
    const machines = document.querySelectorAll('.machine');
    console.log('Makine sayısı:', machines.length);

    // Her makineye olay dinleyicileri ekle
    function setupMachineEl(machine) {
        // Click: handle selection here only (NOT in mousedown to avoid Ctrl double-toggle)
        machine.addEventListener('click', function(e) {
            if (suppressNextClick) { suppressNextClick = false; return; }
            // Sürükleme ile seçim bittikten sonra oluşan click'i yoksay
            if (selectionDragStarted) return;
            if (window.isGroupCreateMode) return;

            if (e.ctrlKey) {
                toggleMachineSelection(this);
            } else if (e.shiftKey && window.selectedMachines.length > 0) {
                e.preventDefault();
                selectRange(this);
            } else {
                clearSelection();
                selectMachine(this);
            }

            // Open info panel for the clicked machine
            if (!e.ctrlKey && !e.shiftKey) {
                showMachineInfoPanel(this);
            }
        });

        // Mousedown: only record for potential drag; selection is handled by click
        machine.addEventListener('mousedown', function(e) {
            if (e.button !== 0) return;
            if (!IS_ADMIN) return;   // personel sürükleyemez
            e.preventDefault();
            e.stopPropagation();

            pendingDragMachine = this;
            pendingDragStartX = e.clientX;
            pendingDragStartY = e.clientY;
            pendingDragCtrl = e.ctrlKey;
        });

        // Right-click context menu
        machine.addEventListener('contextmenu', function(e) {
            e.preventDefault();
            e.stopPropagation();
            if (!this.classList.contains('selected')) {
                clearSelection();
                selectMachine(this);
            }
            showContextMenu(e, this);
        });

        // Tooltip için
        machine.addEventListener('mouseenter', function(e) {
            const note = this.getAttribute('data-note');
            const hubSw = this.getAttribute('data-hub-sw') === '1';
            if ((note && note.trim() !== '') || hubSw) {
                if (tooltipTimer) clearTimeout(tooltipTimer);
                tooltipTimer = setTimeout(() => {
                    showMachineTooltip(this, note || '');
                }, 500);
            }
        });

        machine.addEventListener('mouseleave', function(e) {
            if (tooltipTimer) {
                clearTimeout(tooltipTimer);
                tooltipTimer = null;
            }
            if (activeTooltip) {
                activeTooltip.remove();
                activeTooltip = null;
            }
        });

        // Drag'i engelle
        machine.addEventListener('dragstart', (e) => e.preventDefault());
    }
    machines.forEach(setupMachineEl);
    // Yeni eklenen makineler için de kullanılabilir (map.php tarafından çağrılır)
    window._setupMachineEl = setupMachineEl;

    // Fare hareketi - SÜRÜKLEME İÇİN
    document.addEventListener('mousemove', function(e) {
        // Harita pan
        if (isPanning) {
            e.preventDefault();
            const dx = e.clientX - panStartX;
            const dy = e.clientY - panStartY;
            if (!panMoved && Math.hypot(dx, dy) > PAN_CLICK_THRESHOLD) {
                panMoved = true;
            }
            mapTranslateX = panStartTX + dx;
            mapTranslateY = panStartTY + dy;
            applyMapTransform();
            return;
        }

        // Grup çerçevesi sürükleme (bağımsız delta tabanlı sistem)
        if (groupDragActive && groupDragInitialPositions.length > 0) {
            e.preventDefault();
            const dx = (e.clientX - groupDragStartX) / mapScale;
            const dy = (e.clientY - groupDragStartY) / mapScale;
            groupDragInitialPositions.forEach(pos => {
                pos.element.style.left = Math.max(0, pos.startX + dx) + 'px';
                pos.element.style.top  = Math.max(0, pos.startY + dy) + 'px';
            });
            window.selectedMachines.forEach(m => {
                m.x = parseFloat(m.element.style.left);
                m.y = parseFloat(m.element.style.top);
            });
            updateGroupIcons();
            return; // group drag is exclusive
        }

        // Bekleyen grup sürükleme: 5px eşiği aşınca aktifleştir
        if (pendingGroupDragGid && !groupDragActive) {
            const dist = Math.hypot(e.clientX - pendingGroupDragMouseX, e.clientY - pendingGroupDragMouseY);
            if (dist > 5) {
                const gid = pendingGroupDragGid;
                pendingGroupDragGid = null;
                const grp = window.groupsData[gid];
                if (grp) {
                    // Gruptaki tüm görünür makineleri seç
                    clearSelection();
                    grp.machines.forEach(mid => {
                        const m = document.querySelector(`#map .machine[data-id="${mid}"]`);
                        if (m && m.style.display !== 'none' && m.getAttribute('data-z') === currentFloor) {
                            selectMachine(m);
                        }
                    });
                    if (window.selectedMachines.length > 0) {
                        groupDragStartX = pendingGroupDragMouseX;
                        groupDragStartY = pendingGroupDragMouseY;
                        groupDragInitialPositions = window.selectedMachines.map(m => ({
                            element: m.element,
                            startX: m.x || 0,
                            startY: m.y || 0,
                        }));
                        window.selectedMachines.forEach(m => { m.element.style.zIndex = '999'; });
                        groupDragActive = true;
                    }
                }
            }
        }

        // Makine sürükleme
        if (isMoving && currentMachine && initialPositions.length > 0) {
            e.preventDefault();

            const mapRect = document.getElementById('map').getBoundingClientRect();

            // Divide by mapScale to convert screen-space delta to map coordinate space
            let newX = (e.clientX - mapRect.left - offsetX) / mapScale;
            let newY = (e.clientY - mapRect.top  - offsetY) / mapScale;

            // Prevent negative positions
            newX = Math.max(0, newX);
            newY = Math.max(0, newY);

            const currentStart = initialPositions.find(p => p.element === currentMachine);

            if (currentStart) {
                const dx = newX - currentStart.startX;
                const dy = newY - currentStart.startY;

                initialPositions.forEach(pos => {
                    if (pos.element === currentMachine) {
                        pos.element.style.left = newX + 'px';
                        pos.element.style.top  = newY + 'px';
                    } else {
                        pos.element.style.left = Math.max(0, pos.startX + dx) + 'px';
                        pos.element.style.top  = Math.max(0, pos.startY + dy) + 'px';
                    }
                });

                window.selectedMachines.forEach(m => {
                    m.x = parseFloat(m.element.style.left);
                    m.y = parseFloat(m.element.style.top);
                });
            }

            updateGroupIcons();
        }

        // Pending drag: initiate drag when cursor moves more than 5px from mousedown point
        if (pendingDragMachine && !isMoving) {
            const dist = Math.hypot(e.clientX - pendingDragStartX, e.clientY - pendingDragStartY);
            if (dist > 5) {
                const machine = pendingDragMachine;
                pendingDragMachine = null;

                // Select the machine if not already selected
                if (!machine.classList.contains('selected')) {
                    if (!pendingDragCtrl) clearSelection();
                    selectMachine(machine);
                }

                // Compute offset using the original mousedown position to avoid a jump
                const machineRect = machine.getBoundingClientRect();
                offsetX = pendingDragStartX - machineRect.left;
                offsetY = pendingDragStartY - machineRect.top;

                initialPositions = window.selectedMachines.map(m => ({
                    element: m.element,
                    startX: m.x,
                    startY: m.y,
                    id: m.id
                }));

                currentMachine = machine;
                isMoving = true;

                machine.style.zIndex = '1000';
                window.selectedMachines.forEach(m => {
                    if (m.element !== machine) m.element.style.zIndex = '999';
                });
            }
        }

        // Drag-rectangle selection (5px threshold before showing box)
        if (selectionStart) {
            const dist = Math.hypot(e.clientX - selectionStart.x, e.clientY - selectionStart.y);
            if (dist > 5 || selectionDragStarted) {
                selectionDragStarted = true;
                updateDragSelection(e);
            }
        }
    });

    // Fare bırakma
    document.addEventListener('mouseup', function(e) {
        // Harita pan bitti
        if (isPanning) {
            isPanning = false;
            // panMoved is intentionally NOT reset here; it stays true until the
            // click handler reads it so the follow-up click can be suppressed.
            const container = document.getElementById('map-container');
            if (container) container.style.cursor = '';
            return;
        }

        // Grup çerçevesi sürükleme bitti
        if (groupDragActive) {
            groupDragActive = false;
            window.selectedMachines.forEach(m => { m.element.style.zIndex = ''; });
            groupDragInitialPositions = [];
            suppressNextClick = true;
            // Auto-save moved machines immediately after group drag
            autoSavePositions(window.selectedMachines.slice());
        }

        if (isMoving) {
            if (currentMachine) currentMachine.style.zIndex = '';
            window.selectedMachines.forEach(m => { m.element.style.zIndex = ''; });

            isMoving = false;
            currentMachine = null;
            initialPositions = [];

            window.selectedMachines.forEach(m => {
                m.x = parseFloat(m.element.style.left);
                m.y = parseFloat(m.element.style.top);
            });

            // Suppress the click that fires after mouseup so selection doesn't toggle
            suppressNextClick = true;
            // Auto-save moved machines immediately after drag
            autoSavePositions(window.selectedMachines.slice());
        }

        // If no group drag happened, discard the pending record
        if (pendingGroupDragGid) {
            pendingGroupDragGid = null;
        }

        // If no drag happened, discard the pending drag record (click will handle selection)
        if (pendingDragMachine) {
            pendingDragMachine = null;
        }

        if (selectionStart) {
            endDragSelection();
        }
    });
    
    // Harita pan (sağ fare tuşu) + seçim sürükleme (sol fare tuşu) — #map-container üzerinde
    document.getElementById('map-container').addEventListener('mousedown', function(e) {
        if (isMoving) return;
        if (e.target.closest('.machine')) return;
        if (e.target.closest('#machine-info-panel')) return;
        if (e.target.closest('.group-icon')) return;
        if (e.target.closest('.group-highlight')) return;
        if (e.target.closest('#multi-floor-container')) return;

        if (e.button === 2) {
            // Sağ fare tuşu = harita pan
            const map = document.getElementById('map');
            if (!map || map.style.display === 'none') return;
            isPanning = true;
            panMoved = false;
            panStartX = e.clientX;
            panStartY = e.clientY;
            panStartTX = mapTranslateX;
            panStartTY = mapTranslateY;
            this.style.cursor = 'grabbing';
            e.preventDefault();
        } else if (e.button === 0) {
            // Sol fare tuşu = seçim kutusu (toplu seçim)
            selectionStart = { x: e.clientX, y: e.clientY };
            selectionDragStarted = false;
            selectionBox = document.createElement('div');
            selectionBox.className = 'selection-box';
            selectionBox.style.display = 'none';
            this.appendChild(selectionBox);
        }
    });

    function updateDragSelection(e) {
        if (!selectionStart || !selectionBox) return;

        selectionBox.style.display = 'block';

        const containerRect = document.getElementById('map-container').getBoundingClientRect();

        // Drag rect in screen coordinates
        const screenLeft   = Math.min(selectionStart.x, e.clientX);
        const screenTop    = Math.min(selectionStart.y, e.clientY);
        const screenRight  = Math.max(selectionStart.x, e.clientX);
        const screenBottom = Math.max(selectionStart.y, e.clientY);

        // Position selection box in container-relative coords — no mapScale factor needed
        // because the box is a direct child of #map-container (no transform on it)
        selectionBox.style.left   = (screenLeft   - containerRect.left) + 'px';
        selectionBox.style.top    = (screenTop    - containerRect.top)  + 'px';
        selectionBox.style.width  = (screenRight  - screenLeft)         + 'px';
        selectionBox.style.height = (screenBottom - screenTop)          + 'px';

        const screenRect = { left: screenLeft, top: screenTop, right: screenRight, bottom: screenBottom };

        // Only operate on machines that belong to the current floor
        function isOnCurrentFloor(machine) {
            if (currentFloor === 'all') return true;
            return machine.getAttribute('data-z') === currentFloor;
        }

        if (!e.ctrlKey) {
            document.querySelectorAll('.machine.selected').forEach(m => {
                if (!isMachineInScreenRect(m, screenRect)) deselectMachine(m);
            });
        }

        document.querySelectorAll('#map .machine').forEach(machine => {
            if (isOnCurrentFloor(machine) &&
                machine.style.display !== 'none' &&
                isMachineInScreenRect(machine, screenRect) &&
                !machine.classList.contains('selected')) {
                selectMachine(machine);
            }
        });
    }

    function isMachineInScreenRect(machine, screenRect) {
        const r = machine.getBoundingClientRect();
        return !(r.right < screenRect.left || r.left > screenRect.right ||
                 r.bottom < screenRect.top || r.top > screenRect.bottom);
    }

    function endDragSelection() {
        if (selectionBox) { selectionBox.remove(); selectionBox = null; }
        selectionStart = null;
        // keep selectionDragStarted = true so the follow-up click event doesn't clear the selection;
        // reset it on the next tick
        if (selectionDragStarted) {
            setTimeout(() => { selectionDragStarted = false; }, 0);
        } else {
            selectionDragStarted = false;
        }
    }

    // Harita boş alana tıklama ile seçimi temizle
    document.getElementById('map-container').addEventListener('click', function(e) {
        if (e.target.closest('.machine')) return;
        if (e.target.closest('#machine-info-panel')) return;
        if (e.target.closest('.group-icon')) return;
        if (e.target.closest('.group-highlight')) return;
        if (e.target.closest('#multi-floor-container')) return;
        if (selectionDragStarted) return; // ignore click that ends a drag-select
        // Don't clear selection if a pan just ended with actual movement
        if (panMoved) { panMoved = false; return; }
        clearSelection();
        closeMachineInfoPanel();
    });
    
    // Klavye kısayolları
    document.addEventListener('keydown', function(e) {
        if (e.ctrlKey && e.key === 'a') {
            e.preventDefault();
            selectAllMachines();
        }
        
        if (e.key === 'Escape') {
            clearSelection();
        }
    });
    
    // Arama
    document.getElementById('search')?.addEventListener('input', filterMachines);
    
    // Z filtresi (eski select için uyumluluk)
    document.getElementById('z-filter')?.addEventListener('change', filterByZ);
    
    // Grupları yükle
    loadGroups();
    
    // ========== SEÇİM FONKSİYONLARI ==========
    
    function toggleMachineSelection(machine) {
        const id = machine.getAttribute('data-id');
        const index = window.selectedMachines.findIndex(m => m.id === id);
        
        if (index === -1) {
            selectMachine(machine);
        } else {
            deselectMachine(machine);
        }
    }
    
    function selectMachine(machine) {
        const id = machine.getAttribute('data-id');
        machine.classList.add('selected');
        window.selectedMachines.push({
            id: id,
            element: machine,
            machineNo: machine.getAttribute('data-machine-no'),
            x: parseFloat(machine.style.left) || 0,
            y: parseFloat(machine.style.top)  || 0,
            note: machine.getAttribute('data-note')
        });
        updateSelectionInfo();
        console.log('Makine seçildi, toplam:', window.selectedMachines.length);
    }
    
    function deselectMachine(machine) {
        machine.classList.remove('selected');
        const id = machine.getAttribute('data-id');
        window.selectedMachines = window.selectedMachines.filter(m => m.id !== id);
        updateSelectionInfo();
        console.log('Makine seçimi kaldırıldı, toplam:', window.selectedMachines.length);
    }
    
    function clearSelection() {
        document.querySelectorAll('.machine.selected').forEach(m => {
            m.classList.remove('selected');
        });
        window.selectedMachines = [];
        updateSelectionInfo();
        console.log('Tüm seçimler temizlendi');
    }
    
    function selectRange(targetMachine) {
        const machines = Array.from(document.querySelectorAll('#map .machine'));
        const lastSelected = window.selectedMachines[window.selectedMachines.length - 1];
        const startIndex = machines.findIndex(m => m.getAttribute('data-id') === lastSelected.id);
        const endIndex = machines.findIndex(m => m.getAttribute('data-id') === targetMachine.getAttribute('data-id'));
        
        const [min, max] = [Math.min(startIndex, endIndex), Math.max(startIndex, endIndex)];
        
        for (let i = min; i <= max; i++) {
            const machine = machines[i];
            if (!machine.classList.contains('selected')) {
                selectMachine(machine);
            }
        }
    }
    
    function selectAllMachines() {
        document.querySelectorAll('#map .machine').forEach(machine => {
            const onFloor = currentFloor === 'all' || machine.getAttribute('data-z') === currentFloor;
            if (onFloor && machine.style.display !== 'none' && !machine.classList.contains('selected')) {
                selectMachine(machine);
            }
        });
    }
    
    function updateSelectionInfo() {
        const count = window.selectedMachines.length;
        if (count > 0) {
            showStatus(`${count} makine seçili`);
        }
        
        document.getElementById('noteSelectedCount') && (document.getElementById('noteSelectedCount').innerHTML = count + ' makine seçildi');
        document.getElementById('positionSelectedCount') && (document.getElementById('positionSelectedCount').innerHTML = count + ' makine seçildi');
        document.getElementById('selectedCountText') && (document.getElementById('selectedCountText').innerHTML = count + ' makine seçildi');
    }
    
    // ========== FİLTRELEME ==========
    
    function filterMachines(e) {
        const searchText = (e.target.value || '').trim().toLowerCase();
        
        if (!searchText) {
            // Arama kutusu boşaltıldı — tüm makineleri normale döndür
            document.querySelectorAll('#map .machine').forEach(function(machine) {
                machine.style.opacity = '';
                machine.style.pointerEvents = '';
                // Kat filtresine göre görünürlüğü geri yükle
                if (currentFloor === 'all') {
                    machine.style.display = 'flex';
                } else {
                    machine.style.display = (machine.getAttribute('data-z') === currentFloor) ? 'flex' : 'none';
                }
            });
            return;
        }

        let hasMatch = false;

        document.querySelectorAll('#map .machine').forEach(function(machine) {
            const machineNo  = (machine.getAttribute('data-machine-no') || '').toLowerCase();
            const smibb_ip   = (machine.getAttribute('data-smibb-ip') || '').toLowerCase();
            const mac        = (machine.getAttribute('data-mac') || '').toLowerCase();
            const machinePc  = (machine.getAttribute('data-machine-pc') || '').toLowerCase();

            const matches = machineNo.includes(searchText)
                         || smibb_ip.includes(searchText)
                         || mac.includes(searchText)
                         || machinePc.includes(searchText);

            if (matches) {
                machine.style.display = 'flex';
                machine.style.opacity = '1';
                machine.style.pointerEvents = '';
                hasMatch = true;
            } else {
                machine.style.display = 'flex';   // hâlâ görünür (transparan)
                machine.style.opacity = '0.12';
                machine.style.pointerEvents = 'none';
            }
        });

        // Eşleşen makineleri görünüme sığdır
        if (hasMatch) {
            requestAnimationFrame(function() {
                const MACH_W  = 60;
                const MACH_H  = 60;
                const PADDING = 80;
                const matched = Array.from(document.querySelectorAll('#map .machine'))
                    .filter(function(m) { return parseFloat(m.style.opacity || '1') >= 0.9; });
                if (matched.length === 0) return;
                let minX = Infinity, maxX = -Infinity, minY = Infinity, maxY = -Infinity;
                matched.forEach(function(m) {
                    const x = parseFloat(m.style.left) || 0;
                    const y = parseFloat(m.style.top)  || 0;
                    if (x           < minX) minX = x;
                    if (x + MACH_W  > maxX) maxX = x + MACH_W;
                    if (y           < minY) minY = y;
                    if (y + MACH_H  > maxY) maxY = y + MACH_H;
                });
                const container = document.getElementById('map-container');
                if (!container) return;
                const cw = container.clientWidth;
                const ch = container.clientHeight;
                const contentW = (maxX - minX) + PADDING * 2;
                const contentH = (maxY - minY) + PADDING * 2;
                const newScale = Math.max(MAP_SCALE_MIN,
                                 Math.min(MAP_SCALE_MAX, cw / contentW, ch / contentH));
                mapScale = newScale;
                mapTranslateX = (cw - contentW * newScale) / 2 + (PADDING - minX) * newScale;
                mapTranslateY = (ch - contentH * newScale) / 2 + (PADDING - minY) * newScale;
                applyMapTransform();
            });
        }
    }
    
    function filterByZ(e) {
        const zValue = e.target.value;
        
        document.querySelectorAll('#map .machine').forEach(machine => {
            const machineZ = machine.getAttribute('data-z');
            machine.style.display = (zValue === 'all' || machineZ === zValue) ? 'flex' : 'none';
        });
        // FIX: update group icons after floor filter to remove remnant highlights
        updateGroupIcons();
    }
    
    // Floor tab switching
    window.switchFloor = function(zValue) {
        currentFloor = String(zValue);

        // Update active tab
        document.querySelectorAll('.floor-tab').forEach(tab => tab.classList.remove('active'));
        const activeTab = document.querySelector(`.floor-tab[data-z="${zValue}"]`);
        if (activeTab) activeTab.classList.add('active');

        const map = document.getElementById('map');
        const multi = document.getElementById('multi-floor-container');

        // Always hide the 4-panel container (kept in DOM for backward compatibility but no longer shown)
        if (multi) multi.style.display = 'none';
        map.style.display = 'block';

        if (zValue === 'all') {
            // Show ALL machines on the single canvas
            document.querySelectorAll('#map .machine').forEach(function(machine) {
                machine.style.display = 'flex';
            });
            // Remove any existing floor dividers then redraw them
            document.querySelectorAll('#map .floor-divider').forEach(d => d.remove());
            drawFloorDividers();
            resizeMapToFitMachines();
            updateGroupIcons();
            requestAnimationFrame(function() { fitFloorToView('all'); });
        } else {
            // Single-floor view — remove dividers
            document.querySelectorAll('#map .floor-divider').forEach(d => d.remove());
            document.querySelectorAll('#map .machine').forEach(function(machine) {
                machine.style.display = (machine.getAttribute('data-z') === String(zValue)) ? 'flex' : 'none';
            });
            resizeMapToFitMachines();
            updateGroupIcons();
            requestAnimationFrame(function() { fitFloorToView(zValue); });
        }
    };

    // Draw thin separator lines to indicate floor boundaries in "Tüm Katlar" view.
    // Vertical line at X=2080 (between Z=0,1 on left and Z=2,3 on right)
    // Horizontal line at Y=1130 (between Z=1 top-left and Z=0 bottom-left)
    // Opacity 0.13 is subtle enough to not distract from machine icons but visible on light bg.
    function drawFloorDividers() {
        const map = document.getElementById('map');
        if (!map) return;
        const mapW = parseInt(map.style.width)  || 8000;
        const mapH = parseInt(map.style.height) || 4000;
        const DIVIDER_COLOR = 'rgba(0,0,0,0.13)';

        // Vertical divider
        const vDiv = document.createElement('div');
        vDiv.className = 'floor-divider';
        vDiv.style.cssText = 'position:absolute;left:2080px;top:0;width:2px;height:' + mapH + 'px;background:' + DIVIDER_COLOR + ';pointer-events:none;z-index:0;';
        map.appendChild(vDiv);

        // Horizontal divider (spans full width)
        const hDiv = document.createElement('div');
        hDiv.className = 'floor-divider';
        hDiv.style.cssText = 'position:absolute;left:0;top:1130px;width:' + mapW + 'px;height:2px;background:' + DIVIDER_COLOR + ';pointer-events:none;z-index:0;';
        map.appendChild(hDiv);
    }

    // Tüm makineleri kattaki bounding box'a sığdıracak şekilde zoom/pan ayarla
    function fitFloorToView(zValue) {
        const MACH_W  = 60;
        const MACH_H  = 60;
        const PADDING = 40;

        const selector = (zValue === 'all')
            ? '#map .machine'
            : '#map .machine[data-z="' + zValue + '"]';

        const floorMachines = Array.from(document.querySelectorAll(selector))
            .filter(function(m) { return m.style.display !== 'none'; });
        if (floorMachines.length === 0) { resetZoom(); return; }

        let minX = Infinity, maxX = -Infinity, minY = Infinity, maxY = -Infinity;
        floorMachines.forEach(function(m) {
            const x = parseFloat(m.style.left) || 0;
            const y = parseFloat(m.style.top)  || 0;
            if (x           < minX) minX = x;
            if (x + MACH_W  > maxX) maxX = x + MACH_W;
            if (y           < minY) minY = y;
            if (y + MACH_H  > maxY) maxY = y + MACH_H;
        });

        const container = document.getElementById('map-container');
        const cw = container.clientWidth;
        const ch = container.clientHeight;

        const contentW = (maxX - minX) + PADDING * 2;
        const contentH = (maxY - minY) + PADDING * 2;

        const newScale = Math.max(MAP_SCALE_MIN,
                         Math.min(MAP_SCALE_MAX, cw / contentW, ch / contentH));
        mapScale = newScale;

        // Center content horizontally and vertically in the container
        mapTranslateX = (cw - contentW * newScale) / 2 + (PADDING - minX) * newScale;
        mapTranslateY = (ch - contentH * newScale) / 2 + (PADDING - minY) * newScale;

        applyMapTransform();
    }

    // Populate each floor's mini-map panel with scaled machine clones.
    // If panel dimensions aren't ready yet, schedule a retry via setTimeout.
    function populateMiniMaps() {
        var floorDefs = [
            { z: '0', id: 'mini-map-0' },
            { z: '1', id: 'mini-map-1' },
            { z: '2', id: 'mini-map-2' },
            { z: '3', id: 'mini-map-3' },
        ];

        // Check whether the panels have been laid out yet
        var firstPanel = document.getElementById('mini-map-0');
        if (firstPanel && firstPanel.getBoundingClientRect().width === 0) {
            setTimeout(populateMiniMaps, 50);
            return;
        }

        floorDefs.forEach(function(floor) {
            var panel = document.getElementById(floor.id);
            if (!panel) return;
            panel.innerHTML = '';

            var allM = Array.from(document.querySelectorAll('#map .machine[data-z="' + floor.z + '"]'));
            if (allM.length === 0) return;

            // Use style.left/top (reflects current positions, even unsaved ones)
            var PADDING = 10;
            var xs = allM.map(function(m) { return parseFloat(m.style.left) || parseInt(m.getAttribute('data-x')) || 0; });
            var ys = allM.map(function(m) { return parseFloat(m.style.top)  || parseInt(m.getAttribute('data-y')) || 0; });
            var minX = Math.min.apply(null, xs);
            var maxX = Math.max.apply(null, xs) + 60;
            var minY = Math.min.apply(null, ys);
            var maxY = Math.max.apply(null, ys) + 60;
            var contentW = maxX - minX + PADDING * 2;
            var contentH = maxY - minY + PADDING * 2;

            var rect = panel.getBoundingClientRect();
            var panelW = rect.width  || 500;
            var panelH = rect.height || 300;
            // No upper cap: small floors expand to fill the panel
            var scale  = Math.min(panelW / contentW, panelH / contentH);

            // Center the scaled content inside the panel
            var scaledW = contentW * scale;
            var scaledH = contentH * scale;
            var offsetX = Math.max(0, (panelW - scaledW) / 2);
            var offsetY = Math.max(0, (panelH - scaledH) / 2);

            var wrapper = document.createElement('div');
            wrapper.style.cssText = 'position:absolute;left:' + offsetX + 'px;top:' + offsetY + 'px;pointer-events:none;';

            var canvas = document.createElement('div');
            canvas.style.cssText = 'position:relative;width:' + contentW + 'px;height:' + contentH + 'px;' +
                                   'transform:scale(' + scale + ');transform-origin:0 0;pointer-events:none;';

            allM.forEach(function(m, i) {
                var clone = m.cloneNode(true);
                clone.style.left      = (xs[i] - minX + PADDING) + 'px';
                clone.style.top       = (ys[i] - minY + PADDING) + 'px';
                clone.style.transform = 'rotate(' + (parseInt(m.getAttribute('data-rotation')) || 0) + 'deg)';
                clone.style.display   = 'flex';   // override any display:none from floor filter
                clone.style.cursor    = 'default';
                clone.style.pointerEvents = 'none';
                // Remove data-id so document-wide queries by data-id always find the real
                // machine in #map, not this mini-map thumbnail.
                clone.removeAttribute('data-id');
                canvas.appendChild(clone);
            });

            wrapper.appendChild(canvas);
            panel.appendChild(wrapper);
        });
    }

    // ========== TOOLTIP ==========

    function showMachineTooltip(machine, note) {
        if (activeTooltip) activeTooltip.remove();
        
        const tooltip = document.createElement('div');
        tooltip.className = 'machine-tooltip';
        const machineNo   = machine.getAttribute('data-machine-no');
        const smibb_ip    = machine.getAttribute('data-smibb-ip') || '';
        const screenIp    = machine.getAttribute('data-drscreen-ip') || '';
        const mac         = machine.getAttribute('data-mac') || '';
        const machineType = machine.getAttribute('data-machine-type') || '';
        const gameType    = machine.getAttribute('data-game-type') || '';
        const brand       = machine.getAttribute('data-brand') || '';
        const model       = machine.getAttribute('data-model') || '';
        const machinePc   = machine.getAttribute('data-machine-pc') || '';
        const hubSw       = machine.getAttribute('data-hub-sw') === '1';
        const hubSwCable  = machine.getAttribute('data-hub-sw-cable') || '';
        
        const row = (label, val, color) => val
            ? `<div style="display:flex;gap:6px;margin-top:3px;"><span style="color:#aaa;min-width:80px;font-size:10px;">${label}:</span><span style="font-size:11px;word-break:break-all;${color ? 'color:'+color+';' : ''}">${escapeHtml(val)}</span></div>`
            : '';

        tooltip.innerHTML = `
            <div style="font-weight:bold;margin-bottom:5px;color:#40E0D0;font-size:13px;">${escapeHtml(machineNo)}</div>
            ${row('SMIBB IP', smibb_ip || '-')}
            ${row('Screen IP', screenIp || '-', '#90CAF9')}
            ${row('MAC', mac)}
            ${row('Machine PC', machinePc)}
            ${row('Makine Türü', machineType)}
            ${row('Oyun Türü', gameType)}
            ${row('Marka', brand)}
            ${row('Model', model)}
            ${hubSw ? `<div style="color:#FF9800;font-weight:bold;margin-top:5px;font-size:11px;">🔌 Hub SW${hubSwCable ? ' → ' + escapeHtml(hubSwCable) : ''}</div>` : ''}
            ${note ? `<hr style="margin:6px 0;border:0;border-top:1px solid #444;"><div style="font-size:11px;max-width:200px;word-wrap:break-word;color:#ccc;">${escapeHtml(note)}</div>` : ''}
        `;
        
        document.body.appendChild(tooltip);
        activeTooltip = tooltip;
        positionTooltip(tooltip, machine);
    }
    
    function positionTooltip(tooltip, machine) {
        const machineRect = machine.getBoundingClientRect();
        const tooltipRect = tooltip.getBoundingClientRect();
        
        let left = machineRect.left + (machineRect.width / 2) - (tooltipRect.width / 2);
        let top = machineRect.top - tooltipRect.height - 10;
        
        if (top < 10) top = machineRect.bottom + 10;
        if (left < 10) left = 10;
        else if (left + tooltipRect.width > window.innerWidth - 10) left = window.innerWidth - tooltipRect.width - 10;
        
        tooltip.style.left = left + 'px';
        tooltip.style.top = top + 'px';
        tooltip.style.visibility = 'visible';
        tooltip.style.opacity = '1';
    }
    
    // ========== GRUP FONKSİYONLARI ==========
    
    function loadGroups() {
        fetch('get_groups.php')
            .then(response => response.json())
            .then(groups => {
                window.groupsData = groups;
                updateGroupsPanel();
                updateGroupIcons();
                updateGroupFilter();
            })
            .catch(error => console.log('Grup yüklenemedi:', error));
    }
    
    function updateGroupsPanel() {
        const groupsList = document.getElementById('groupsList');
        if (!groupsList) return;

        const FLOOR_LABELS = {
            '0': '🏛 Yüksek Tavan',
            '1': '🏠 Alçak Tavan',
            '2': '👑 Yeni VIP Salon',
            '3': '🎰 Alt Salon',
        };
        const FLOOR_ORDER = ['0', '1', '2', '3'];

        // Grubun baskın katını (Z) makine data-z değerlerine göre hesapla
        function getGroupFloor(group) {
            const floorCount = {};
            group.machines.forEach(id => {
                const machine = document.querySelector(`#map .machine[data-id="${id}"]`);
                if (machine) {
                    const z = machine.getAttribute('data-z') || '?';
                    floorCount[z] = (floorCount[z] || 0) + 1;
                }
            });
            const keys = Object.keys(floorCount);
            if (keys.length === 0) return 'other';
            return keys.reduce((a, b) => floorCount[a] >= floorCount[b] ? a : b);
        }

        // Grupları kata göre demetlere ayır
        const buckets = { '0': [], '1': [], '2': [], '3': [], 'other': [] };
        for (let groupId in window.groupsData) {
            const group = window.groupsData[groupId];
            const floor = getGroupFloor(group);
            const key = FLOOR_ORDER.includes(floor) ? floor : 'other';
            buckets[key].push(groupId);
        }

        // Grup kartı HTML'i oluştur
        function buildGroupCard(groupId) {
            const group = window.groupsData[groupId];
            const color = group.color || '#4CAF50';
            const adminButtons = IS_ADMIN ? `
                <button class="assign-btn" onclick="assignSelectedToGroup(${groupId})"><i class="fas fa-arrow-right"></i> Seçilileri Ata</button>
                <button class="delete-btn" onclick="deleteGroup(${groupId})" title="Sil"><i class="fas fa-trash"></i></button>
            ` : '';
            // Grup adı: admin ise inline düzenlenebilir input, değilse sadece metin
            const nameHtml = IS_ADMIN
                ? `<input type="text" value="${escapeHtml(group.name)}"
                        style="border:none;background:transparent;font-weight:bold;font-size:13px;
                               width:100%;outline:none;padding:0;cursor:text;"
                        onblur="renameGroup(${groupId}, this.value)"
                        onkeydown="if(event.key==='Enter')this.blur();">`
                : escapeHtml(group.name);
            return `
                <div class="group-item-panel" id="group-panel-${groupId}" style="border-left: 4px solid ${escapeHtml(color)};">
                    <div class="group-item-header">
                        <span class="group-item-name" style="display:flex; align-items:center; gap:6px; flex:1; min-width:0;">
                            <span style="display:inline-block; width:12px; height:12px; border-radius:50%; background:${escapeHtml(color)}; flex-shrink:0;"></span>
                            ${nameHtml}
                        </span>
                        <div style="display:flex; align-items:center; gap:4px; flex-shrink:0;">
                            ${IS_ADMIN ? `<input type="color" value="${escapeHtml(color)}" title="Grup rengi" style="width:24px;height:24px;padding:0;border:none;cursor:pointer;border-radius:4px;" onchange="changeGroupColor(${groupId}, this.value)">` : ''}
                            <span class="group-item-count" style="background:${escapeHtml(color)};">${group.machines.length}</span>
                        </div>
                    </div>
                    ${IS_ADMIN ? `
                    <div class="group-machine-input">
                        <input type="text" id="machine-input-${groupId}" placeholder="Makine no girin" onkeypress="handleMachineInput(event, ${groupId})">
                        <button onclick="addMachineToGroup(${groupId})"><i class="fas fa-plus"></i></button>
                    </div>` : ''}
                    <div class="group-machine-list" id="machine-list-${groupId}">${getMachineListHtml(group.machines, groupId)}</div>
                    <div class="group-actions">
                        ${adminButtons}
                        ${IS_ADMIN ? `<button class="export-btn" onclick="exportGroup(${groupId})" title="Excel aktar"><i class="fas fa-file-excel"></i></button>` : ''}
                        <button class="show-btn" onclick="showGroup(${groupId})" title="Göster"><i class="fas fa-eye"></i></button>
                    </div>
                </div>
            `;
        }

        let html = '';
        [...FLOOR_ORDER, 'other'].forEach(floorKey => {
            const groupIds = buckets[floorKey];
            if (groupIds.length === 0) return;
            const label = FLOOR_LABELS[floorKey] || '📋 Diğer';
            const bodyId = 'floor-body-' + floorKey;
            // Başlangıçta kapalı (collapsed) olarak render et
            html += `<div class="floor-section-header collapsed" onclick="toggleFloorSection('${bodyId}', this)">${label}</div>`;
            html += `<div class="floor-section-body collapsed" id="${bodyId}" style="max-height:0;">`;
            groupIds.forEach(gid => { html += buildGroupCard(gid); });
            html += `</div>`;
        });

        if (html === '') {
            html = '<div style="padding: 20px; text-align: center; color: #999;">Henüz grup yok</div>';
        }
        groupsList.innerHTML = html;
    }
    
    function getMachineListHtml(machineIds, groupId) {
        if (!machineIds || machineIds.length === 0) {
            return '<div style="color: #999; text-align: center;">Grupta makine yok</div>';
        }
        let html = '';
        machineIds.forEach(id => {
            const machine = document.querySelector(`#map .machine[data-id="${id}"]`);
            const machineNo = machine ? machine.getAttribute('data-machine-no') : 'ID: ' + id;
            const hubSw = machine ? machine.getAttribute('data-hub-sw') === '1' : false;
            const hubSwCable = machine ? (machine.getAttribute('data-hub-sw-cable') || '') : '';
            const hubSwLabel = hubSw ? `<span style="color:#FF9800; font-weight:bold;" title="Hub SW${hubSwCable ? ': ' + escapeHtml(hubSwCable) : ''}"> 🔌${hubSwCable ? '<small> → ' + escapeHtml(hubSwCable) + '</small>' : ''}</span>` : '';
            const removeBtn = IS_ADMIN ? `<button onclick="removeMachineFromGroup(${id}, ${groupId})"><i class="fas fa-times"></i></button>` : '';
            html += `<div class="group-machine-item"><span>${escapeHtml(machineNo)}${hubSwLabel}</span>${removeBtn}</div>`;
        });
        return html;
    }
    
    function updateGroupFilter() {
        const datalist = document.getElementById('group-filter-list');
        if (!datalist) return;

        const FLOOR_LABELS = {
            '0': '🏛 Yüksek Tavan',
            '1': '🏠 Alçak Tavan',
            '2': '👑 Yeni VIP Salon',
            '3': '🎰 Alt Salon',
        };
        const FLOOR_ORDER = ['0', '1', '2', '3'];

        function getGroupFloor(group) {
            const floorCount = {};
            group.machines.forEach(id => {
                const m = document.querySelector(`#map .machine[data-id="${id}"]`);
                if (m) { const z = m.getAttribute('data-z') || '?'; floorCount[z] = (floorCount[z] || 0) + 1; }
            });
            const keys = Object.keys(floorCount);
            if (keys.length === 0) return 'other';
            return keys.reduce((a, b) => floorCount[a] >= floorCount[b] ? a : b);
        }

        // Kat bazlı bucket
        const buckets = { '0': [], '1': [], '2': [], '3': [], 'other': [] };
        for (let groupId in window.groupsData) {
            const floor = getGroupFloor(window.groupsData[groupId]);
            const key = FLOOR_ORDER.includes(floor) ? floor : 'other';
            buckets[key].push({ id: groupId, name: window.groupsData[groupId].name, floor: key });
        }

        let html = '<option value="Tüm Gruplar">';
        [...FLOOR_ORDER, 'other'].forEach(fk => {
            if (buckets[fk].length === 0) return;
            const label = FLOOR_LABELS[fk] || 'Diğer';
            buckets[fk].forEach(g => {
                html += `<option value="${escapeHtml(g.name)}" data-id="${escapeHtml(g.id)}" data-floor="${escapeHtml(label)}">`;
            });
        });
        datalist.innerHTML = html;
    }
    
    function updateGroupIcons() {
        // Eski ikonları temizle
        document.querySelectorAll('.group-icon, .group-highlight').forEach(el => el.remove());
        
        const map = document.getElementById('map');
        
        // Her grup için ikon oluştur
        for (let groupId in window.groupsData) {
            const group = window.groupsData[groupId];
            if (group.machines.length === 0) continue;
            
            const color = group.color || '#4CAF50';
            
            // Gruba ait görünür makinelerin sınırlarını hesapla
            let minX = Infinity, maxX = -Infinity, minY = Infinity, maxY = -Infinity;
            let hasNote = false;
            let visibleCount = 0;
            
            group.machines.forEach(machineId => {
                const machine = document.querySelector(`#map .machine[data-id="${machineId}"]`);
                // Only include machines on the active floor; ignore the 'all' overview
                // Also skip hidden machines (filtered out by search / group filter)
                if (machine && machine.style.display !== 'none' &&
                    (currentFloor === 'all' || machine.getAttribute('data-z') === currentFloor)) {
                    visibleCount++;
                    const x = parseFloat(machine.style.left);
                    const y = parseFloat(machine.style.top);
                    if (!isNaN(x) && !isNaN(y)) {
                        minX = Math.min(minX, x);
                        maxX = Math.max(maxX, x + 60);
                        minY = Math.min(minY, y);
                        maxY = Math.max(maxY, y + 60);
                    }

                    if (machine.classList.contains('has-note')) {
                        hasNote = true;
                    }
                }
            });
            
            // Skip group if no machines are visible on the current floor
            if (visibleCount === 0 || minX === Infinity) continue;
            
            // Grup vurgulama çerçevesi - her gruba ait renk
            const highlight = document.createElement('div');
            highlight.className = 'group-highlight';
            highlight.style.left = (minX - 8) + 'px';
            highlight.style.top = (minY - 8) + 'px';
            highlight.style.width = (maxX - minX + 16) + 'px';
            highlight.style.height = (maxY - minY + 16) + 'px';
            highlight.style.borderColor = color;
            highlight.setAttribute('data-group-id', groupId);

            // Group highlight is decorative only — pointer-events:none (CSS default)
            // so machines inside receive all mouse events (click, contextmenu, drag).
            // Group drag is now triggered via right-click → "Grubu Taşı" context menu item.
            
            // Grup ikonu - çerçevenin üstünde ortada (ikon boyutu 30px)
            const centerX = (minX + maxX) / 2;
            
            const icon = document.createElement('div');
            icon.className = `group-icon ${hasNote ? 'has-note' : ''}`;
            icon.innerHTML = '<i class="fas fa-server"></i>';
            icon.style.left = centerX + 'px';
            icon.style.top = (minY - 22) + 'px';
            icon.style.background = color;
            icon.style.borderColor = color;
            icon.setAttribute('data-group-id', groupId);
            
            // Tooltip ekle
            const tooltip = document.createElement('span');
            tooltip.className = 'tooltip-text';
            tooltip.textContent = group.name + ' (' + visibleCount + ')';
            icon.appendChild(tooltip);
            
            // Tıklama olayı - grubu panelde göster
            icon.onclick = (e) => {
                e.stopPropagation();
                const panel = document.getElementById('groupsPanel');
                if (panel && panel.style.display === 'none') {
                    toggleGroupsPanel();
                }
                // Scroll to this group in the panel
                setTimeout(() => {
                    const groupPanel = document.getElementById(`group-panel-${groupId}`);
                    if (groupPanel) {
                        groupPanel.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
                        groupPanel.style.boxShadow = `0 0 12px ${color}`;
                        setTimeout(() => { groupPanel.style.boxShadow = ''; }, 2000);
                    }
                }, 100);
            };
            
            map.appendChild(highlight);
            map.appendChild(icon);
        }
    }
    
    // Seçili makineleri gruba ata
    window.assignSelectedToGroup = function(groupId) {
        if (window.selectedMachines.length === 0) {
            showStatus('Lütfen önce makine seçin!');
            return;
        }
        
        const machineIds = window.selectedMachines.map(m => m.id);
        
        if (confirm(`${machineIds.length} makineyi "${window.groupsData[groupId].name}" grubuna eklemek istediğinize emin misiniz?`)) {
            const formData = new FormData();
            formData.append('group_id', groupId);
            machineIds.forEach(id => formData.append('machine_ids[]', id));
            
            fetch('add_machines_to_group.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    window.groupsData[groupId].machines = [...new Set([...window.groupsData[groupId].machines, ...machineIds.map(id => parseInt(id))])];
                    document.getElementById(`machine-list-${groupId}`).innerHTML = getMachineListHtml(window.groupsData[groupId].machines, groupId);
                    updateGroupIcons();
                    showStatus('Makineler gruba eklendi!');
                } else {
                    showStatus('Hata: ' + data.error);
                }
            });
        }
    };
    
    // Gruba makine ekle (makine numarası ile)
    window.addMachineToGroup = function(groupId) {
        const input = document.getElementById(`machine-input-${groupId}`);
        const machineNo = input.value.trim();
        
        if (!machineNo) {
            showStatus('Lütfen bir makine numarası girin');
            return;
        }
        
        const machineId = findMachineIdByNo(machineNo);
        if (!machineId) {
            showStatus('Makine bulunamadı!');
            return;
        }
        
        if (window.groupsData[groupId].machines.includes(parseInt(machineId))) {
            showStatus('Makine zaten grupta!');
            return;
        }
        
        const formData = new FormData();
        formData.append('group_id', groupId);
        formData.append('machine_id', machineId);
        
        fetch('add_machine_to_group.php', {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                window.groupsData[groupId].machines.push(parseInt(machineId));
                document.getElementById(`machine-list-${groupId}`).innerHTML = getMachineListHtml(window.groupsData[groupId].machines, groupId);
                input.value = '';
                updateGroupIcons();
                showStatus('Makine gruba eklendi');
            } else {
                showStatus('Hata: ' + data.error);
            }
        });
    };
    
    function findMachineIdByNo(machineNo) {
        machineNo = machineNo.trim().toUpperCase();
        const machines = document.querySelectorAll('#map .machine');
        for (let machine of machines) {
            const no = machine.getAttribute('data-machine-no').trim().toUpperCase();
            if (no === machineNo) {
                return machine.getAttribute('data-id');
            }
        }
        return null;
    }
    
    window.handleMachineInput = function(event, groupId) {
        if (event.key === 'Enter') {
            addMachineToGroup(groupId);
        }
    };
    
    window.removeMachineFromGroup = function(machineId, groupId) {
        if (!confirm('Makineyi gruptan çıkarmak istediğinize emin misiniz?')) return;
        
        const formData = new FormData();
        formData.append('group_id', groupId);
        formData.append('machine_id', machineId);
        
        fetch('remove_machine_from_group.php', {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                window.groupsData[groupId].machines = window.groupsData[groupId].machines.filter(id => id != machineId);
                document.getElementById(`machine-list-${groupId}`).innerHTML = getMachineListHtml(window.groupsData[groupId].machines, groupId);
                updateGroupIcons();
                showStatus('Makine gruptan çıkarıldı');
            }
        });
    };
    
    // Grup oluşturma modu
    window.startGroupCreate = function() {
        window.isGroupCreateMode = true;
        window.selectedForGroup = new Set();
        document.body.classList.add('group-create-mode');
        
        const toolbar = document.createElement('div');
        toolbar.id = 'groupCreateToolbar';
        toolbar.className = 'group-create-toolbar';
        toolbar.innerHTML = `
            <span class="count" id="selectedCount">0</span>
            <input type="text" id="newGroupName" placeholder="Grup adı">
            <input type="color" id="newGroupColor" value="#4CAF50" title="Grup rengi" style="width:36px;height:36px;padding:2px;border:none;cursor:pointer;border-radius:6px;">
            <button class="save-btn" onclick="saveGroup()"><i class="fas fa-save"></i> Kaydet</button>
            <button class="cancel-btn" onclick="cancelGroupCreate()"><i class="fas fa-times"></i> İptal</button>
        `;
        document.body.appendChild(toolbar);
        
        document.querySelectorAll('#map .machine').forEach(machine => {
            machine.addEventListener('click', toggleMachineForGroup);
        });
        showStatus('Makineleri seçin ve grup adı girin');
    };
    
    function toggleMachineForGroup(e) {
        e.stopPropagation();
        const machine = e.currentTarget;
        const id = machine.getAttribute('data-id');
        
        if (window.selectedForGroup.has(id)) {
            window.selectedForGroup.delete(id);
            machine.classList.remove('selected-for-group');
        } else {
            window.selectedForGroup.add(id);
            machine.classList.add('selected-for-group');
        }
        document.getElementById('selectedCount').innerHTML = window.selectedForGroup.size;
    }
    
    window.saveGroup = function() {
        const groupName = document.getElementById('newGroupName').value.trim();
        
        if (!groupName) {
            alert('Lütfen bir grup adı girin!');
            return;
        }
        
        const colorEl = document.getElementById('newGroupColor');
        const color = colorEl ? colorEl.value : '#4CAF50';
        
        const formData = new FormData();
        formData.append('group_name', groupName);
        formData.append('color', color);
        
        if (window.selectedForGroup && window.selectedForGroup.size > 0) {
            window.selectedForGroup.forEach(id => formData.append('machine_ids[]', id));
        }
        
        fetch('save_group.php', {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                cancelGroupCreate();
                loadGroups();
                showStatus('Grup oluşturuldu!');
            } else {
                alert('Hata: ' + data.error);
            }
        });
    };
    
    window.cancelGroupCreate = function() {
        window.isGroupCreateMode = false;
        document.body.classList.remove('group-create-mode');
        
        const toolbar = document.getElementById('groupCreateToolbar');
        if (toolbar) toolbar.remove();
        
        document.querySelectorAll('#map .machine').forEach(machine => {
            machine.removeEventListener('click', toggleMachineForGroup);
            machine.classList.remove('selected-for-group');
        });
        
        window.selectedForGroup = new Set();
    };
    
    // Grup atama modalı
    window.showAssignGroupModal = function() {
        if (window.selectedMachines.length === 0) {
            showStatus('Lütfen önce makine seçin!');
            return;
        }
        
        const modal = document.getElementById('assignGroupModal');
        const select = document.getElementById('assignGroupSelect');
        
        let options = '<option value="">Grup seçin</option>';
        for (let groupId in window.groupsData) {
            options += `<option value="${groupId}">${escapeHtml(window.groupsData[groupId].name)} (${window.groupsData[groupId].machines.length} makine)</option>`;
        }
        select.innerHTML = options;
        
        document.getElementById('selectedCountText').innerHTML = window.selectedMachines.length + ' makine seçildi';
        modal.style.display = 'block';
    };
    
    window.closeAssignGroupModal = function() {
        document.getElementById('assignGroupModal').style.display = 'none';
    };
    
    window.assignToSelectedGroup = function() {
        const groupId = document.getElementById('assignGroupSelect').value;
        if (!groupId) {
            showStatus('Lütfen bir grup seçin!');
            return;
        }
        
        assignSelectedToGroup(groupId);
        closeAssignGroupModal();
    };
    
    // Grup işlemleri
    window.exportGroup = function(groupId) {
        window.location.href = 'export_group.php?group_id=' + groupId;
    };
    
    window.showGroup = function(groupId) {
        const color = (window.groupsData[groupId] && window.groupsData[groupId].color) || '#4CAF50';
        document.querySelectorAll('#map .machine').forEach(machine => {
            const machineId = machine.getAttribute('data-id');
            if (window.groupsData[groupId] && window.groupsData[groupId].machines.includes(parseInt(machineId))) {
                machine.style.opacity = '1';
                machine.style.outline = `3px solid ${color}`;
                machine.style.outlineOffset = '2px';
            } else {
                machine.style.opacity = '0.3';
            }
        });
        
        setTimeout(() => {
            document.querySelectorAll('#map .machine').forEach(machine => {
                machine.style.opacity = '';
                machine.style.outline = '';
                machine.style.outlineOffset = '';
            });
        }, 5000);
    };
    
    window.filterByGroup = function(groupId) {
        if (groupId === 'all') {
            // Restore floor-specific visibility (don't blindly show every floor's machines)
            document.querySelectorAll('#map .machine').forEach(machine => {
                if (currentFloor === 'all') {
                    machine.style.display = 'flex';
                } else {
                    machine.style.display = (machine.getAttribute('data-z') === currentFloor) ? 'flex' : 'none';
                }
            });
        } else {
            document.querySelectorAll('#map .machine').forEach(machine => {
                const machineId = machine.getAttribute('data-id');
                const inGroup = window.groupsData[groupId] && window.groupsData[groupId].machines.includes(parseInt(machineId));
                const onFloor = currentFloor === 'all' || machine.getAttribute('data-z') === currentFloor;
                machine.style.display = (inGroup && onFloor) ? 'flex' : 'none';
            });
        }
        updateGroupIcons();
        // Re-center view on the now-visible machines
        requestAnimationFrame(function() { fitFloorToView(currentFloor); });
    };

    // Yazılabilir group-filter input handler
    window.filterByGroupInput = function(value) {
        const trimmed = value.trim();
        if (!trimmed || trimmed === 'Tüm Gruplar') {
            window.filterByGroup('all');
            return;
        }
        // Grup adı ile eşleş (case-insensitive)
        for (let gId in window.groupsData) {
            if (window.groupsData[gId].name.toLowerCase() === trimmed.toLowerCase()) {
                window.filterByGroup(gId);
                return;
            }
        }
        // Eşleşme yoksa tümünü göster
        window.filterByGroup('all');
    };

    window.clearGroupFilter = function() {
        const inp = document.getElementById('group-filter');
        if (inp) inp.value = '';
        window.filterByGroup('all');
    };
    
    window.deleteGroup = function(groupId) {
        if (!confirm('Grubu silmek istediğinize emin misiniz?')) return;
        
        fetch('delete_group.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
            },
            body: 'group_id=' + groupId
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                delete window.groupsData[groupId];
                updateGroupsPanel();
                updateGroupIcons();
                updateGroupFilter();
                showStatus('Grup silindi!');
            }
        });
    };
    
    window.toggleGroupsPanel = function() {
        const panel = document.getElementById('groupsPanel');
        if (panel) {
            panel.style.display = panel.style.display === 'none' ? 'block' : 'none';
            if (panel.style.display === 'block') {
                loadGroups();
            }
        }
    };

    window.toggleFloorSection = function(bodyId, headerEl) {
        const body = document.getElementById(bodyId);
        if (!body) return;
        const collapsed = body.classList.toggle('collapsed');
        if (headerEl) headerEl.classList.toggle('collapsed', collapsed);
        // Açılınca max-height'ı ger, kapanınca 0
        body.style.maxHeight = collapsed ? '0' : '9999px';
    };
    
    // ========== NOT FONKSİYONLARI ==========
    
    window.openNoteModal = function() {
        if (window.selectedMachines.length === 0) {
            showStatus('Lütfen önce makine seçin!');
            return;
        }
        
        const firstNote = window.selectedMachines[0].note;
        const allSameNote = window.selectedMachines.every(m => m.note === firstNote);
        
        document.getElementById('noteText').value = allSameNote ? (firstNote || '') : '';
        document.getElementById('noteModal').style.display = 'block';
    };
    
    window.closeNoteModal = function() {
        document.getElementById('noteModal').style.display = 'none';
        document.getElementById('noteText').value = '';
    };
    
    window.saveNote = function() {
        const note = document.getElementById('noteText').value.trim();
        
        if (window.selectedMachines.length === 0) {
            closeNoteModal();
            return;
        }
        
        const promises = window.selectedMachines.map(machine => {
            const formData = new FormData();
            formData.append('id', machine.id);
            formData.append('note', note);
            
            return fetch('update_note.php', {
                method: 'POST',
                body: formData
            });
        });
        
        Promise.all(promises).then(() => {
            window.selectedMachines.forEach(machine => {
                machine.element.setAttribute('data-note', note);
                machine.note = note;
                
                if (note) {
                    machine.element.classList.add('has-note');
                    if (!machine.element.querySelector('.note-indicator')) {
                        const indicator = document.createElement('div');
                        indicator.className = 'note-indicator';
                        indicator.title = 'Not var';
                        machine.element.appendChild(indicator);
                    }
                } else {
                    machine.element.classList.remove('has-note');
                    const indicator = machine.element.querySelector('.note-indicator');
                    if (indicator) indicator.remove();
                }
            });
            
            closeNoteModal();
            showStatus('Notlar kaydedildi!');
            updateGroupIcons();
        });
    };
    
    // ========== POZİSYON FONKSİYONLARI ==========
    
    window.openPositionModal = function() {
        if (window.selectedMachines.length === 0) {
            showStatus('Lütfen önce makine seçin!');
            return;
        }
        
        const firstMachine = window.selectedMachines[0];
        document.getElementById('posX').value = firstMachine.x;
        document.getElementById('posY').value = firstMachine.y;
        document.getElementById('posZ').value = firstMachine.element.getAttribute('data-z');
        
        document.getElementById('positionModal').style.display = 'block';
    };
    
    window.closePositionModal = function() {
        document.getElementById('positionModal').style.display = 'none';
    };
    
    window.savePosition = function() {
        const x = parseInt(document.getElementById('posX').value);
        const y = parseInt(document.getElementById('posY').value);
        const z = document.getElementById('posZ').value;
        
        if (isNaN(x) || isNaN(y)) {
            showStatus('Geçerli koordinat girin!');
            return;
        }
        
        // Sadece negatif değerleri engelle; harita resizeMapToFitMachines() ile büyüyebilir
        const validX = Math.max(0, x);
        const validY = Math.max(0, y);
        
        const promises = window.selectedMachines.map(machine => {
            const formData = new FormData();
            formData.append('id', machine.id);
            formData.append('x', validX);
            formData.append('y', validY);
            formData.append('z', z);
            formData.append('rotation', machine.element.getAttribute('data-rotation') || 0);
            
            return fetch('update_position.php', {
                method: 'POST',
                body: formData
            }).then(() => {
                machine.element.style.left = validX + 'px';
                machine.element.style.top = validY + 'px';
                machine.element.setAttribute('data-z', z);
                // data-x / data-y attribute'larını güncelle ki info panel doğru değeri göstersin
                machine.element.setAttribute('data-x', validX);
                machine.element.setAttribute('data-y', validY);
                machine.x = validX;
                machine.y = validY;
                // Z etiketini makine ikonunun içinde güncelle
                const zSpan = machine.element.querySelector('.z-level');
                if (zSpan) zSpan.textContent = 'Z:' + z;
            });
        });
        
        Promise.all(promises).then(() => {
            closePositionModal();
            // Katman görünürlüğünü yeniden uygula — Z değiştiğinde makine doğru katmana taşınsın.
            switchFloor(currentFloor);
            // Refresh info panel so Z layer change is immediately visible
            if (window.selectedMachines.length > 0) {
                const panel = document.getElementById('machine-info-panel');
                if (panel && panel.classList.contains('open')) {
                    window.showMachineInfoPanel(window.selectedMachines[0].element);
                }
            }
            showStatus('Pozisyonlar güncellendi!');
        });
    };
    
    // ========== HİZALAMA FONKSİYONLARI (GÜNCELLENMİŞ) ==========
    
    window.alignSelected = function(direction) {
        if (window.selectedMachines.length < 2) {
            showStatus('En az 2 makine seçmelisiniz');
            return;
        }
        
        let targetX, targetY;
        
        switch(direction) {
            case 'left':
                // Sola hizala - en soldaki makinenin X pozisyonu
                targetX = Math.min(...window.selectedMachines.map(m => m.x));
                window.selectedMachines.forEach(m => {
                    m.element.style.left = targetX + 'px';
                    m.x = targetX;
                });
                showStatus('Makineler sola hizalandı');
                break;
                
            case 'right':
                // Sağa hizala - en sağdaki makinenin sağ kenarına göre
                targetX = Math.max(...window.selectedMachines.map(m => m.x + 60)) - 60;
                window.selectedMachines.forEach(m => {
                    m.element.style.left = targetX + 'px';
                    m.x = targetX;
                });
                showStatus('Makineler sağa hizalandı');
                break;
                
            case 'top':
                // Üste hizala - en üstteki makinenin Y pozisyonu
                targetY = Math.min(...window.selectedMachines.map(m => m.y));
                window.selectedMachines.forEach(m => {
                    m.element.style.top = targetY + 'px';
                    m.y = targetY;
                });
                showStatus('Makineler üste hizalandı');
                break;
                
            case 'bottom':
                // Alta hizala - en alttaki makinenin alt kenarına göre
                targetY = Math.max(...window.selectedMachines.map(m => m.y + 60)) - 60;
                window.selectedMachines.forEach(m => {
                    m.element.style.top = targetY + 'px';
                    m.y = targetY;
                });
                showStatus('Makineler alta hizalandı');
                break;
                
            case 'centerH':
                // Yatay ortala - seçili makinelerin yatay merkezine göre
                const minX = Math.min(...window.selectedMachines.map(m => m.x));
                const maxX = Math.max(...window.selectedMachines.map(m => m.x + 60));
                targetX = (minX + maxX) / 2 - 30;
                window.selectedMachines.forEach(m => {
                    m.element.style.left = targetX + 'px';
                    m.x = targetX;
                });
                showStatus('Makineler yatay ortalandı');
                break;
                
            case 'centerV':
                // Dikey ortala - seçili makinelerin dikey merkezine göre
                const minY = Math.min(...window.selectedMachines.map(m => m.y));
                const maxY = Math.max(...window.selectedMachines.map(m => m.y + 60));
                targetY = (minY + maxY) / 2 - 30;
                window.selectedMachines.forEach(m => {
                    m.element.style.top = targetY + 'px';
                    m.y = targetY;
                });
                showStatus('Makineler dikey ortalandı');
                break;

            case 'distributeH': {
                // Yatay eşit dağıt
                if (window.selectedMachines.length < 3) {
                    showStatus('En az 3 makine seçmelisiniz');
                    return;
                }
                const sortedH = [...window.selectedMachines].sort((a, b) => a.x - b.x);
                const firstX = sortedH[0].x;
                const lastX  = sortedH[sortedH.length - 1].x;
                const stepH  = (lastX - firstX) / (sortedH.length - 1);
                sortedH.forEach((m, i) => {
                    const newX = firstX + i * stepH;
                    m.element.style.left = newX + 'px';
                    m.x = newX;
                });
                showStatus('Makineler yatay eşit dağıtıldı');
                break;
            }

            case 'distributeV': {
                // Dikey eşit dağıt
                if (window.selectedMachines.length < 3) {
                    showStatus('En az 3 makine seçmelisiniz');
                    return;
                }
                const sortedV = [...window.selectedMachines].sort((a, b) => a.y - b.y);
                const firstY = sortedV[0].y;
                const lastY  = sortedV[sortedV.length - 1].y;
                const stepV  = (lastY - firstY) / (sortedV.length - 1);
                sortedV.forEach((m, i) => {
                    const newY = firstY + i * stepV;
                    m.element.style.top = newY + 'px';
                    m.y = newY;
                });
                showStatus('Makineler dikey eşit dağıtıldı');
                break;
            }
        }
        
        updateGroupIcons();
    };

    // ========== OTOMATİK DÜZENLEME ==========

    window.autoArrangeSelected = function() {
        if (window.selectedMachines.length < 2) {
            showStatus('En az 2 makine seçmelisiniz');
            return;
        }

        const MACHINE_W = 60;
        const MACHINE_H = 60;
        const GAP = 8;
        const COLS = Math.ceil(Math.sqrt(window.selectedMachines.length));

        // Seçili makinelerin en küçük x,y koordinatından başla
        const startX = Math.min(...window.selectedMachines.map(m => m.x));
        const startY = Math.min(...window.selectedMachines.map(m => m.y));

        // Makine numarasına göre sırala
        const sorted = [...window.selectedMachines].sort((a, b) => {
            const noA = a.machineNo || '';
            const noB = b.machineNo || '';
            return noA.localeCompare(noB, undefined, { numeric: true });
        });

        sorted.forEach((m, i) => {
            const col = i % COLS;
            const row = Math.floor(i / COLS);
            const newX = startX + col * (MACHINE_W + GAP);
            const newY = startY + row * (MACHINE_H + GAP);
            m.element.style.left = newX + 'px';
            m.element.style.top  = newY + 'px';
            m.x = newX;
            m.y = newY;
        });

        updateGroupIcons();
        autoSavePositions(window.selectedMachines.slice());
    };
    
    // ========== TOOLBAR FONKSİYONLARI ==========

    
    window.toggleTheme = function() {
        document.body.classList.toggle('dark-theme');
        document.body.classList.toggle('light-theme');
        const icon = document.querySelector('.theme-toggle i');
        if (icon) {
            icon.className = document.body.classList.contains('dark-theme') ? 'fas fa-sun' : 'fas fa-moon';
        }
    };
    
    window.goToDashboard = function() {
        window.location.href = 'dashboard.php';
    };

    window.toggleSidebar = function() {
        const sidebar = document.getElementById('sidebar');
        const overlay = document.getElementById('sidebarOverlay');
        if (sidebar) sidebar.classList.toggle('open');
        if (overlay) overlay.classList.toggle('open');
    };

    // ========== ZOOM / PAN ==========

    function applyMapTransform() {
        const map = document.getElementById('map');
        if (map) {
            map.style.transform = `translate(${mapTranslateX}px, ${mapTranslateY}px) scale(${mapScale})`;
        }
    }

    function zoomToPoint(newScale, containerX, containerY) {
        newScale = Math.max(MAP_SCALE_MIN, Math.min(MAP_SCALE_MAX, newScale));
        const cursorMapX = (containerX - mapTranslateX) / mapScale;
        const cursorMapY = (containerY - mapTranslateY) / mapScale;
        mapTranslateX = containerX - cursorMapX * newScale;
        mapTranslateY = containerY - cursorMapY * newScale;
        mapScale = newScale;
        applyMapTransform();
    }

    window.zoomIn = function() {
        const container = document.getElementById('map-container');
        const r = container.getBoundingClientRect();
        zoomToPoint(mapScale * 1.2, r.width / 2, r.height / 2);
    };

    window.zoomOut = function() {
        const container = document.getElementById('map-container');
        const r = container.getBoundingClientRect();
        zoomToPoint(mapScale / 1.2, r.width / 2, r.height / 2);
    };

    window.resetZoom = function() {
        const MACH_W  = 60;
        const MACH_H  = 60;
        const PADDING = 40;
        const selector = currentFloor === 'all'
            ? '#map .machine'
            : '#map .machine[data-z="' + currentFloor + '"]';
        const floorMachines = Array.from(document.querySelectorAll(selector))
            .filter(function(m) { return m.style.display !== 'none'; });
        if (floorMachines.length === 0) {
            mapScale = 1; mapTranslateX = 0; mapTranslateY = 0;
            applyMapTransform(); return;
        }
        let minX = Infinity, maxX = -Infinity, minY = Infinity, maxY = -Infinity;
        floorMachines.forEach(function(m) {
            const x = parseFloat(m.style.left) || 0;
            const y = parseFloat(m.style.top)  || 0;
            if (x           < minX) minX = x;
            if (x + MACH_W  > maxX) maxX = x + MACH_W;
            if (y           < minY) minY = y;
            if (y + MACH_H  > maxY) maxY = y + MACH_H;
        });
        const container = document.getElementById('map-container');
        const cw = container.clientWidth;
        const ch = container.clientHeight;
        const contentW = (maxX - minX) + PADDING * 2;
        const contentH = (maxY - minY) + PADDING * 2;
        mapScale = Math.max(MAP_SCALE_MIN, Math.min(MAP_SCALE_MAX, cw / contentW, ch / contentH));
        mapTranslateX = (cw - contentW * mapScale) / 2 + (PADDING - minX) * mapScale;
        mapTranslateY = (ch - contentH * mapScale) / 2 + (PADDING - minY) * mapScale;
        applyMapTransform();
    };

    // Scroll-wheel zoom to cursor (max 10×)
    document.getElementById('map-container').addEventListener('wheel', function(e) {
        const map = document.getElementById('map');
        if (!map || map.style.display === 'none') return; // ignore on 4-panel view
        e.preventDefault();
        const factor = e.deltaY < 0 ? 1.1 : (1 / 1.1);
        const r = this.getBoundingClientRect();
        zoomToPoint(mapScale * factor, e.clientX - r.left, e.clientY - r.top);
    }, { passive: false });

    window.rotateSelected = function(angle) {
        if (window.selectedMachines.length > 0) {
            window.selectedMachines.forEach(machine => {
                const currentRotation = parseInt(machine.element.getAttribute('data-rotation') || 0);
                const newRotation = (currentRotation + angle) % 360;
                machine.element.setAttribute('data-rotation', newRotation);
                machine.element.style.transform = `rotate(${newRotation}deg)`;
                applyInnerRotation(machine.element);
            });
            // Auto-save rotation changes immediately
            autoSavePositions(window.selectedMachines.slice());
        } else {
            showStatus('Lütfen önce makine seçin');
        }
    };
    
    window.saveAllPositions = function() {
        const promises = [];
        document.querySelectorAll('#map .machine').forEach(machine => {
            const id = machine.getAttribute('data-id');
            const x = parseInt(machine.style.left);
            const y = parseInt(machine.style.top);
            const rotation = machine.getAttribute('data-rotation') || 0;
            const z = machine.getAttribute('data-z') || 0;
            
            promises.push(
                fetch('update_position.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                    },
                    body: `id=${id}&x=${x}&y=${y}&z=${z}&rotation=${rotation}`
                }).then(() => {
                    // data-x / data-y attribute'larını güncelle ki info panel doğru değeri göstersin
                    machine.setAttribute('data-x', x);
                    machine.setAttribute('data-y', y);
                })
            );
        });
        
        Promise.all(promises).then(() => {
            resizeMapToFitMachines();
            showStatus('Tüm değişiklikler kaydedildi!');
        }).catch(() => {
            showStatus('Kaydetme hatası!');
        });
    };
    
    // ========== YARDIMCI FONKSİYONLAR ==========

    // Auto-save a list of selectedMachines objects to the server immediately.
    // Used after drag-and-drop to make saving transparent (no manual save needed).
    function autoSavePositions(machines) {
        if (!machines || machines.length === 0) return;
        Promise.all(machines.map(function(m) {
            var fd = new FormData();
            fd.append('id', m.id);
            fd.append('x', Math.round(parseFloat(m.element.style.left) || 0));
            fd.append('y', Math.round(parseFloat(m.element.style.top)  || 0));
            fd.append('z', m.element.getAttribute('data-z') || 0);
            fd.append('rotation', m.element.getAttribute('data-rotation') || 0);
            return fetch('update_position.php', { method: 'POST', body: fd });
        })).then(function() {
            showStatus('Pozisyonlar kaydedildi!');
        }).catch(function() {
            showStatus('Kaydetme hatası! Tekrar deneyin.');
        });
    }

    // Keep machine labels upright regardless of the machine element's CSS rotation.
    // Called once on init and after every rotation update.
    function applyMachineInnerRotations() {
        document.querySelectorAll('#map .machine').forEach(applyInnerRotation);
    }

    function applyInnerRotation(machine) {
        const inner = machine.querySelector('.machine-inner');
        if (!inner) return;
        // Labels rotate naturally with the machine outer element (no counter-rotation)
        inner.style.transform = '';
    }

    // Resize #map so it physically contains every machine at its data coordinate.
    // This keeps group frames (which use the same coordinate system) aligned with
    // machines and ensures no machine is "outside the map".
    function resizeMapToFitMachines() {
        const map = document.getElementById('map');
        const container = document.getElementById('map-container');
        if (!map || !container) return;

        let maxX = 0, maxY = 0;
        document.querySelectorAll('#map .machine').forEach(function(m) {
            const x = parseFloat(m.style.left) || 0;
            const y = parseFloat(m.style.top)  || 0;
            if (x + 60 > maxX) maxX = x + 60;
            if (y + 60 > maxY) maxY = y + 60;
        });

        const PADDING = 120;
        // Never shrink below the container's natural size
        const minW = Math.max(maxX + PADDING, container.clientWidth  || 800);
        const minH = Math.max(maxY + PADDING, container.clientHeight || 600);

        map.style.width  = minW + 'px';
        map.style.height = minH + 'px';
    }

    function hexToRgba(hex, alpha) {
        const a = (typeof alpha === 'number' && isFinite(alpha)) ? alpha : 1;
        if (!hex || !/^#[0-9A-Fa-f]{6}$/.test(hex)) {
            return `rgba(76, 175, 80, ${a})`; // varsayılan yeşil
        }
        const r = parseInt(hex.slice(1, 3), 16);
        const g = parseInt(hex.slice(3, 5), 16);
        const b = parseInt(hex.slice(5, 7), 16);
        return `rgba(${r}, ${g}, ${b}, ${a})`;
    }
    
    function escapeHtml(text) {
        if (!text) return '';
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }
    
    // ========== HUB SW FONKSİYONLARI ==========
    
    window.openHubSwModal = function() {
        if (window.selectedMachines.length === 0) {
            showStatus('Lütfen önce makine seçin!');
            return;
        }
        const machine = window.selectedMachines[0];
        const hubSw = machine.element.getAttribute('data-hub-sw') === '1';
        const hubSwCable = machine.element.getAttribute('data-hub-sw-cable') || '';
        
        document.getElementById('hubSwCheck').checked = hubSw;
        document.getElementById('hubSwCable').value = hubSwCable;
        document.getElementById('hubSwMachineInfo').textContent = `Makine: ${machine.machineNo}` +
            (window.selectedMachines.length > 1 ? ` (+${window.selectedMachines.length - 1} daha)` : '');
        document.getElementById('hubSwModal').style.display = 'block';
    };
    
    window.closeHubSwModal = function() {
        document.getElementById('hubSwModal').style.display = 'none';
    };
    
    window.saveHubSw = function() {
        const hubSw = document.getElementById('hubSwCheck').checked ? 1 : 0;
        const cable = document.getElementById('hubSwCable').value.trim();
        
        const promises = window.selectedMachines.map(machine => {
            const formData = new FormData();
            formData.append('machine_id', machine.id);
            formData.append('hub_sw', hubSw);
            formData.append('hub_sw_cable', cable);
            
            return fetch('update_machine_hubsw.php', {
                method: 'POST',
                body: formData
            }).then(() => {
                machine.element.setAttribute('data-hub-sw', hubSw);
                machine.element.setAttribute('data-hub-sw-cable', cable);
                if (hubSw) {
                    machine.element.classList.add('has-hub-sw');
                    if (!machine.element.querySelector('.hub-sw-badge')) {
                        const badge = document.createElement('div');
                        badge.className = 'hub-sw-badge';
                        badge.textContent = '🔌';
                        badge.title = `Hub SW${cable ? ': ' + cable : ''}`;
                        machine.element.appendChild(badge);
                    } else {
                        machine.element.querySelector('.hub-sw-badge').title = `Hub SW${cable ? ': ' + cable : ''}`;
                    }
                } else {
                    machine.element.classList.remove('has-hub-sw');
                    const badge = machine.element.querySelector('.hub-sw-badge');
                    if (badge) badge.remove();
                }
            });
        });
        
        Promise.all(promises).then(() => {
            closeHubSwModal();
            showStatus('Hub SW bilgileri kaydedildi!');
            updateGroupIcons();
            // Refresh group panel machine lists
            for (let groupId in window.groupsData) {
                const listEl = document.getElementById(`machine-list-${groupId}`);
                if (listEl) {
                    listEl.innerHTML = getMachineListHtml(window.groupsData[groupId].machines, groupId);
                }
            }
        });
    };
    
    // ========== GRUP RENK FONKSİYONU ==========
    
    window.changeGroupColor = function(groupId, color) {
        if (!window.groupsData[groupId]) return;
        const formData = new FormData();
        formData.append('group_id', groupId);
        formData.append('color', color);
        
        fetch('update_group.php', {
            method: 'POST',
            body: formData
        }).then(r => r.json()).then(data => {
            if (data.success) {
                window.groupsData[groupId].color = color;
                updateGroupIcons();
                // Update panel border color
                const panel = document.getElementById(`group-panel-${groupId}`);
                if (panel) panel.style.borderLeftColor = color;
                showStatus('Grup rengi güncellendi!');
            }
        });
    };

    window.renameGroup = function(groupId, newName) {
        const trimmed = newName.trim();
        if (!trimmed || !window.groupsData[groupId]) return;
        if (trimmed === window.groupsData[groupId].name) return; // değişmedi
        const formData = new FormData();
        formData.append('group_id', groupId);
        formData.append('group_name', trimmed);
        fetch('update_group.php', { method: 'POST', body: formData })
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    window.groupsData[groupId].name = trimmed;
                    updateGroupFilter();
                    showStatus('Grup adı güncellendi!');
                }
            });
    };
    
    // ========== SAĞ TIK BAĞLAM MENÜSÜ ==========

    let contextMenuMachine = null;

    function showContextMenu(e, machine) {
        hideContextMenu();
        contextMenuMachine = machine;
        const menu = document.getElementById('context-menu');
        if (!menu) return;

        // ── Dinamik grup işlemleri ──────────────────────────────────────────────
        const machineId = parseInt(machine.getAttribute('data-id'));
        const machineNo = machine.getAttribute('data-machine-no') || '?';
        const memberGroups = Object.entries(window.groupsData)
            .filter(([, g]) => g.machines.includes(machineId));

        const opsEl = document.getElementById('ctx-group-ops');
        if (opsEl) {
            let html = '';
            if (IS_ADMIN) {
                if (memberGroups.length > 0) {
                    // Gruba bağlı her grup için "Taşı", "Ayır" ve "Grubu Boz" seçenekleri
                    memberGroups.forEach(([gid, g]) => {
                        const safeGid = parseInt(gid) || 0;
                        html += `<div class="context-menu-item" onclick="contextMenuAction('moveGroup','${safeGid}')">` +
                                `<i class="fas fa-arrows-alt" style="color:#2196F3"></i> "${escapeHtml(g.name)}" grubunu taşı</div>`;
                        html += `<div class="context-menu-item" onclick="contextMenuAction('removeFromGroup','${safeGid}')">` +
                                `<i class="fas fa-unlink" style="color:#f44336"></i> "${escapeHtml(g.name)}" grubundan ayır</div>`;
                        html += `<div class="context-menu-item" onclick="contextMenuAction('dissolveGroup','${safeGid}')">` +
                                `<i class="fas fa-layer-group" style="color:#FF9800"></i> "${escapeHtml(g.name)}" grubunu boz</div>`;
                    });
                    html += '<div class="context-menu-separator"></div>';
                } else {
                    // Grupta değil → Gruba Dahil Et
                    html += `<div class="context-menu-item" onclick="contextMenuAction('addToGroup')">` +
                            `<i class="fas fa-object-group" style="color:#9C27B0"></i> Gruba Dahil Et</div>`;
                    html += '<div class="context-menu-separator"></div>';
                }
            }
            opsEl.innerHTML = html;
        }

        menu.style.display = 'block';
        let x = e.clientX, y = e.clientY;
        if (x + 230 > window.innerWidth)  x = window.innerWidth  - 235;
        if (y + 300 > window.innerHeight) y = window.innerHeight - 305;
        menu.style.left = x + 'px';
        menu.style.top  = y + 'px';
    }

    function hideContextMenu() {
        const menu = document.getElementById('context-menu');
        if (menu) menu.style.display = 'none';
        contextMenuMachine = null;
    }

    window.contextMenuAction = function(action, param) {
        // Save before hideContextMenu() clears contextMenuMachine
        const targetMachine = contextMenuMachine;
        hideContextMenu();
        switch (action) {
            case 'note':        openNoteModal();             break;
            case 'createGroup': quickCreateGroupFromSelection(); break;
            case 'position':    openPositionModal();         break;
            case 'hubsw':       openHubSwModal();            break;
            case 'info':
                if (window.selectedMachines.length > 0) {
                    showMachineInfoPanel(window.selectedMachines[0].element);
                }
                break;
            case 'removeFromGroup':
                if (targetMachine && param) {
                    const mid = parseInt(targetMachine.getAttribute('data-id'));
                    removeMachineFromGroup(mid, param);
                }
                break;
            case 'dissolveGroup':
                if (param) deleteGroup(param);
                break;
            case 'moveGroup': {
                // Select all visible machines in the group so they move together
                const gid = param;
                const grp = window.groupsData[gid];
                if (!grp) break;
                clearSelection();
                grp.machines.forEach(machineId => {
                    const m = document.querySelector(`#map .machine[data-id="${machineId}"]`);
                    if (m && m.style.display !== 'none') selectMachine(m);
                });
                showStatus(`"${escapeHtml(grp.name)}" grubu seçildi — sürükleyerek taşıyın`);
                break;
            }
            case 'addToGroup':
                // Restore so openAddToGroupModal / confirmAddToGroup can use it
                contextMenuMachine = targetMachine;
                openAddToGroupModal();
                break;
        }
    };

    // Quick-create group from current selection: starts startGroupCreate() and pre-marks selected machines
    function quickCreateGroupFromSelection() {
        if (window.selectedMachines.length === 0) {
            showStatus('Lütfen önce makine seçin!');
            return;
        }
        const preSelected = window.selectedMachines.slice(); // snapshot
        startGroupCreate();
        // Pre-mark selected machines in the group-create mode
        preSelected.forEach(sm => {
            const machine = sm.element;
            const id = machine.getAttribute('data-id');
            if (!window.selectedForGroup.has(id)) {
                window.selectedForGroup.add(id);
                machine.classList.add('selected-for-group');
            }
        });
        const countEl = document.getElementById('selectedCount');
        if (countEl) countEl.innerHTML = window.selectedForGroup.size;
    }

    function showMachineGroupInfo() {
        if (window.selectedMachines.length === 0) return;
        const machineId = parseInt(window.selectedMachines[0].element.getAttribute('data-id'));
        const machineNo = window.selectedMachines[0].machineNo;
        const groups = Object.values(window.groupsData).filter(g => g.machines.includes(machineId));
        if (groups.length === 0) {
            showStatus(`${machineNo} henüz hiçbir gruba atanmamış`);
        } else {
            showStatus(`${machineNo} → Gruplar: ${groups.map(g => g.name).join(', ')}`);
        }
    }

    // ========== GRUBA DAHİL ET MODAL ==========

    window.openAddToGroupModal = function() {
        if (!contextMenuMachine) return;
        const machineNo = contextMenuMachine.getAttribute('data-machine-no') || '?';
        const infoEl = document.getElementById('addToGroupMachineInfo');
        if (infoEl) infoEl.textContent = `Makine: ${machineNo}`;

        // Autocomplete listesini doldur
        const dl = document.getElementById('groupNameList');
        if (dl) {
            dl.innerHTML = Object.values(window.groupsData)
                .map(g => `<option value="${escapeHtml(g.name)}">${escapeHtml(g.name)}</option>`)
                .join('');
        }
        const input = document.getElementById('addToGroupName');
        if (input) input.value = '';

        const modal = document.getElementById('addToGroupModal');
        if (modal) {
            modal.style.display = 'block';
            if (input) setTimeout(() => input.focus(), 50);
            if (input) {
                input.onkeydown = function(e) { if (e.key === 'Enter') confirmAddToGroup(); };
            }
        }
    };

    window.closeAddToGroupModal = function() {
        const modal = document.getElementById('addToGroupModal');
        if (modal) modal.style.display = 'none';
    };

    window.confirmAddToGroup = function() {
        const input = document.getElementById('addToGroupName');
        const name = (input ? input.value.trim() : '').toLowerCase();
        if (!name) { showStatus('Grup adı girin!'); return; }

        // Grup adına göre grubu bul
        const match = Object.entries(window.groupsData)
            .find(([, g]) => g.name.trim().toLowerCase() === name);

        if (!match) {
            showStatus(`"${name}" adında bir grup bulunamadı!`);
            return;
        }
        const [groupId] = match;
        const machineId = parseInt(contextMenuMachine.getAttribute('data-id'));

        if (match[1].machines.includes(machineId)) {
            showStatus('Makine zaten bu grupta!');
            closeAddToGroupModal();
            return;
        }

        const formData = new FormData();
        formData.append('group_id', groupId);
        formData.append('machine_id', machineId);

        fetch('add_machine_to_group.php', { method: 'POST', body: formData })
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    window.groupsData[groupId].machines.push(machineId);
                    const listEl = document.getElementById(`machine-list-${groupId}`);
                    if (listEl) listEl.innerHTML = getMachineListHtml(window.groupsData[groupId].machines, groupId);
                    updateGroupIcons();
                    showStatus('Makine gruba eklendi!');
                } else {
                    showStatus('Hata: ' + (data.error || 'Bilinmeyen hata'));
                }
            });

        closeAddToGroupModal();
    };

    // Hide context menu when clicking elsewhere
    document.addEventListener('click', function(e) {
        const menu = document.getElementById('context-menu');
        if (menu && !menu.contains(e.target)) hideContextMenu();
    });
    document.addEventListener('contextmenu', function(e) {
        if (e.target.closest('.machine')) return; // Let machine handler show context menu
        // Empty map area: suppress browser context menu (right-click is used for panning)
        e.preventDefault();
        hideContextMenu();
    });

    // ========== MAKİNE BİLGİ PANELİ ==========

    window.showMachineInfoPanel = function(machine) {
        const panel = document.getElementById('machine-info-panel');
        if (!panel) return;

        const machineNo    = machine.getAttribute('data-machine-no') || '-';
        const ip           = machine.getAttribute('data-smibb-ip')  || '-';
        const drscreenIp   = machine.getAttribute('data-drscreen-ip') || '';
        const mac          = machine.getAttribute('data-mac')  || '-';
        const machineType  = machine.getAttribute('data-machine-type') || '';
        const gameType     = machine.getAttribute('data-game-type') || '';
        const brand        = machine.getAttribute('data-brand') || '';
        const model        = machine.getAttribute('data-model') || '';
        const machinePc    = machine.getAttribute('data-machine-pc') || '';
        const seriNumber   = machine.getAttribute('data-seri-number') || '';
        const posX= Math.round(parseFloat(machine.style.left) || parseInt(machine.getAttribute('data-x')) || 0);
        const posY         = Math.round(parseFloat(machine.style.top)  || parseInt(machine.getAttribute('data-y')) || 0);
        const rotation     = parseInt(machine.getAttribute('data-rotation') || 0);
        const z            = machine.getAttribute('data-z')   || '0';
        const note         = machine.getAttribute('data-note') || '';
        const hubSw        = machine.getAttribute('data-hub-sw') === '1';
        const hubSwCable   = machine.getAttribute('data-hub-sw-cable') || '';
        const machineId    = parseInt(machine.getAttribute('data-id'));

        const floorNames = { '0': '🏛 Yüksek Tavan', '1': '🏠 Alçak Tavan', '2': '👑 Yeni VIP Salon', '3': '🎰 Alt Salon' };

        const groupTags = Object.entries(window.groupsData)
            .filter(([, g]) => g.machines.includes(machineId))
            .map(([, g]) => `<span class="info-group-tag" style="background:${escapeHtml(g.color || '#4CAF50')}">${escapeHtml(g.name)}</span>`)
            .join('');

        document.getElementById('info-machine-no').textContent = machineNo;
        document.getElementById('info-panel-body').innerHTML = `
            <div class="info-row"><div class="info-label">Kat</div><div class="info-value">${floorNames[z] || z}</div></div>
            <div class="info-row"><div class="info-label">Koordinat (X, Y)</div><div class="info-value">${posX}, ${posY} &nbsp;<span style="opacity:0.7;font-size:11px;">(${rotation}°)</span></div></div>
            <div class="info-row"><div class="info-label">SMIBB IP</div><div class="info-value">${escapeHtml(ip)}</div></div>
            <div class="info-row"><div class="info-label">Screen IP</div><div class="info-value" style="color:#2196F3;">${escapeHtml(drscreenIp) || '-'}</div></div>
            <div class="info-row"><div class="info-label">MAC Adresi</div><div class="info-value" style="font-size:11px;">${escapeHtml(mac)}</div></div>
            ${machinePc ? `<div class="info-row"><div class="info-label">Machine PC</div><div class="info-value">${escapeHtml(machinePc)}</div></div>` : ''}
            ${seriNumber ? `<div class="info-row"><div class="info-label">Seri No</div><div class="info-value">${escapeHtml(seriNumber)}</div></div>` : ''}
            ${machineType? `<div class="info-row"><div class="info-label">Makine Türü</div><div class="info-value">${escapeHtml(machineType)}</div></div>` : ''}
            ${gameType ? `<div class="info-row"><div class="info-label">Oyun Türü</div><div class="info-value">${escapeHtml(gameType)}</div></div>` : ''}
            ${brand ? `<div class="info-row"><div class="info-label">Marka</div><div class="info-value">${escapeHtml(brand)}</div></div>` : ''}
            ${model ? `<div class="info-row"><div class="info-label">Model</div><div class="info-value">${escapeHtml(model)}</div></div>` : ''}
            ${hubSw ? `<div class="info-row"><div class="info-label">🔌 Hub SW</div><div class="info-value">${escapeHtml(hubSwCable) || 'Var'}</div></div>` : ''}
            <div class="info-row"><div class="info-label">Gruplar</div><div class="info-value">${groupTags || '<span style="color:#aaa">Grup yok</span>'}</div></div>
            ${note ? `<div class="info-row"><div class="info-label">Not</div><div class="info-value">${escapeHtml(note)}</div></div>` : ''}
            ${IS_ADMIN ? `
            <div style="margin-top:14px; display:flex; flex-direction:column; gap:6px;">
                <button class="info-panel-btn save-btn" onclick="openNoteModal()"><i class="fas fa-sticky-note"></i> Not ekle / düzenle</button>
                <button class="info-panel-btn" style="background:#2196F3; color:white;" onclick="openPositionModal()"><i class="fas fa-map-marker-alt"></i> Konum ayarla</button>
            </div>` : ''}
        `;

        panel.classList.add('open');
    };

    window.closeMachineInfoPanel = function() {
        const panel = document.getElementById('machine-info-panel');
        if (panel) panel.classList.remove('open');
    };

    function showStatus(message) {
        console.log('Status:', message);
        const status = document.getElementById('status');
        if (status) {
            status.textContent = message;
            status.style.display = 'block';
            setTimeout(() => {
                status.style.display = 'none';
            }, 3000);
        }
    }

    // ========== PDF EXPORT ==========

    window.exportFloorPDF = async function() {
        if (typeof window.jspdf === 'undefined' || typeof html2canvas === 'undefined') {
            alert('PDF kütüphaneleri yüklenemedi. Lütfen sayfayı yenileyip tekrar deneyin.');
            return;
        }

        const floorNames = {
            'all': 'Tüm Katlar',
            '0': 'Yüksek Tavan',
            '1': 'Alçak Tavan',
            '2': 'Yeni VIP Salon',
            '3': 'Alt Salon'
        };

        const floorName = floorNames[currentFloor] || ('Kat ' + currentFloor);

        // Collect only machine no / hub-sw / note for the active floor
        const machines = [];
        document.querySelectorAll('#map .machine').forEach(function(el) {
            if (currentFloor !== 'all' && el.getAttribute('data-z') !== String(currentFloor)) return;
            if (el.style.display === 'none') return;
            const hubSw     = el.getAttribute('data-hub-sw') === '1';
            const hubCable  = el.getAttribute('data-hub-sw-cable') || '';
            machines.push({
                machineNo: el.getAttribute('data-machine-no') || '',
                hubSwText: hubSw ? ('Var' + (hubCable ? ' → ' + hubCable : '')) : '',
                note:      el.getAttribute('data-note') || ''
            });
        });

        machines.sort(function(a, b) {
            return a.machineNo.localeCompare(b.machineNo, 'tr', { numeric: true });
        });

        showStatus('PDF hazırlanıyor...');

        // Temporarily close info panel so it does not appear in the screenshot
        const infoPanel = document.getElementById('machine-info-panel');
        const infoPanelWasOpen = infoPanel && infoPanel.classList.contains('open');
        if (infoPanelWasOpen) infoPanel.classList.remove('open');

        // Fit the view to the active floor so the screenshot captures only those machines
        fitFloorToView(currentFloor);
        await new Promise(function(resolve) { requestAnimationFrame(function() { requestAnimationFrame(resolve); }); });

        try {
            const mapContainer = document.getElementById('map-container');
            const canvas = await html2canvas(mapContainer, {
                useCORS: true,
                allowTaint: true,
                scale: 1,
                logging: false
            });

            if (infoPanelWasOpen) infoPanel.classList.add('open');

            const { jsPDF } = window.jspdf;

            // Page 1 – landscape A4, map image
            const pdf = new jsPDF({ orientation: 'landscape', unit: 'mm', format: 'a4' });
            const pageW = pdf.internal.pageSize.getWidth();
            const pageH = pdf.internal.pageSize.getHeight();

            const now = new Date();
            const dateStr = now.toLocaleDateString('tr-TR') + ' ' + now.toLocaleTimeString('tr-TR', { hour: '2-digit', minute: '2-digit' });

            pdf.setFontSize(16);
            pdf.setTextColor(44, 125, 50);
            pdf.text('Casino Map – ' + floorName, 14, 14);

            pdf.setFontSize(9);
            pdf.setTextColor(120, 120, 120);
            pdf.text(dateStr, pageW - 14, 14, { align: 'right' });
            pdf.text('Toplam Makine: ' + machines.length, 14, 20);

            const imgData = canvas.toDataURL('image/jpeg', 0.85);
            const maxImgW = pageW - 28;
            const maxImgH = pageH - 28;
            const aspect  = canvas.width / canvas.height;
            let drawW = maxImgW;
            let drawH = drawW / aspect;
            if (drawH > maxImgH) { drawH = maxImgH; drawW = drawH * aspect; }

            pdf.addImage(imgData, 'JPEG', 14, 24, drawW, drawH);

            // Page 2 – portrait A4, slim table (Makine No / Hub SW / Not)
            if (machines.length > 0) {
                pdf.addPage('a4', 'portrait');

                pdf.setFontSize(14);
                pdf.setTextColor(44, 125, 50);
                pdf.text('Makine Listesi – ' + floorName, 14, 14);

                pdf.setFontSize(9);
                pdf.setTextColor(120, 120, 120);
                pdf.text(dateStr, 196, 14, { align: 'right' });

                const tableRows = machines.map(function(m) {
                    return [m.machineNo, m.hubSwText, m.note];
                });

                pdf.autoTable({
                    startY: 20,
                    head: [['Makine No', 'Hub SW', 'Not']],
                    body: tableRows,
                    styles: { fontSize: 9, cellPadding: 3, overflow: 'linebreak' },
                    headStyles: { fillColor: [76, 175, 80], textColor: 255, fontSize: 9, fontStyle: 'bold' },
                    alternateRowStyles: { fillColor: [245, 250, 245] },
                    columnStyles: {
                        0: { cellWidth: 30 },
                        1: { cellWidth: 50 },
                        2: { cellWidth: 'auto' }
                    },
                    margin: { left: 14, right: 14 }
                });
            }

            const safeName = floorName.replace(/[^\w\s]/g, '').trim().replace(/\s+/g, '-');
            pdf.save('casino-map-' + safeName + '-' + now.toISOString().slice(0, 10) + '.pdf');
            showStatus('PDF indirildi!');
        } catch (err) {
            if (infoPanelWasOpen) infoPanel.classList.add('open');
            console.error('PDF hatası:', err);
            alert('PDF oluşturulurken hata oluştu: ' + err.message);
        }
    };

    // Initialise: expand map, apply label rotations, then show the 4-panel overview
    resizeMapToFitMachines();
    applyMachineInnerRotations();
    switchFloor('all');
});