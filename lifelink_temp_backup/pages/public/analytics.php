<?php
/**
 * LifeLink – Analytics Dashboard (public)
 */
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/helpers.php';

startSecureSession();

$pageTitle       = 'Analytics Dashboard';
$metaDescription = 'LifeLink blood donation analytics: donor availability by blood group, request urgency, and monthly trends.';
require __DIR__ . '/../../includes/header.php';
?>
<div class="page-header">
    <div class="container">
        <h1>📊 Analytics Dashboard</h1>
        <p>Real-time blood donation network statistics</p>
    </div>
</div>
<section class="section-sm">
    <div class="container">
        <div class="grid-2 mb-8">
            <!-- Chart: Eligible donors per blood group -->
            <div class="card">
                <div class="card-header"><h2 style="font-size:var(--text-base)">Available Eligible Donors by Blood Group</h2></div>
                <div class="card-body">
                    <div class="chart-container"><canvas id="donorsByGroupChart"></canvas></div>
                </div>
            </div>
            <!-- Chart: Requests by urgency -->
            <div class="card">
                <div class="card-header"><h2 style="font-size:var(--text-base)">Requests by Urgency</h2></div>
                <div class="card-body">
                    <div class="chart-container"><canvas id="requestsByUrgencyChart"></canvas></div>
                </div>
            </div>
        </div>
        <!-- Chart: Monthly trend -->
        <div class="card mb-8">
            <div class="card-header"><h2 style="font-size:var(--text-base)">Monthly Requests vs Fulfilled (Last 6 Months)</h2></div>
            <div class="card-body">
                <div class="chart-container" style="height:300px"><canvas id="monthlyTrendChart"></canvas></div>
            </div>
        </div>
        <!-- Shortage flags -->
        <div class="card">
            <div class="card-header"><h2 style="font-size:var(--text-base)">⚠️ Area Shortage Alerts</h2></div>
            <div class="card-body" id="shortageContainer">
                <div class="spinner"></div> Loading...
            </div>
        </div>
    </div>
</section>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<script>
const RED    = '#c0392b';
const REDS   = ['#e74c3c','#c0392b','#a93226','#922b21','#7b241c','#641e16','#f1948a','#fadbd8'];
const GREEN  = '#10b981';
const BLUE   = '#3b82f6';

// ── Donors by blood group ──
fetch(APP_URL + '/api/charts.php?type=donors_by_group')
    .then(r=>r.json()).then(data=>{
        new Chart(document.getElementById('donorsByGroupChart'),{
            type:'bar',
            data:{
                labels: data.map(d=>d.blood_group),
                datasets:[{
                    label:'Eligible Donors',
                    data: data.map(d=>d.count),
                    backgroundColor: REDS,
                    borderRadius: 6,
                }]
            },
            options:{responsive:true,maintainAspectRatio:false,plugins:{legend:{display:false}},scales:{y:{beginAtZero:true,ticks:{stepSize:1}}}}
        });
    });

// ── Requests by urgency ──
fetch(APP_URL + '/api/charts.php?type=requests_by_urgency')
    .then(r=>r.json()).then(data=>{
        new Chart(document.getElementById('requestsByUrgencyChart'),{
            type:'doughnut',
            data:{
                labels: data.map(d=>d.urgency),
                datasets:[{data:data.map(d=>d.count), backgroundColor:['#e74c3c','#f59e0b','#10b981'], borderWidth:0}]
            },
            options:{responsive:true,maintainAspectRatio:false,plugins:{legend:{position:'bottom'}}}
        });
    });

// ── Monthly trend ──
fetch(APP_URL + '/api/charts.php?type=monthly_trend')
    .then(r=>r.json()).then(data=>{
        new Chart(document.getElementById('monthlyTrendChart'),{
            type:'line',
            data:{
                labels: data.map(d=>d.month),
                datasets:[
                    {label:'Requests',data:data.map(d=>d.requests),borderColor:RED,backgroundColor:'rgba(192,57,43,0.1)',fill:true,tension:0.4},
                    {label:'Fulfilled',data:data.map(d=>d.fulfilled),borderColor:GREEN,backgroundColor:'rgba(16,185,129,0.1)',fill:true,tension:0.4}
                ]
            },
            options:{responsive:true,maintainAspectRatio:false,plugins:{legend:{position:'top'}},scales:{y:{beginAtZero:true,ticks:{stepSize:1}}}}
        });
    });

// ── Shortage flags ──
fetch(APP_URL + '/api/charts.php?type=shortages')
    .then(r=>r.json()).then(data=>{
        const container = document.getElementById('shortageContainer');
        if(!data.length){
            container.innerHTML='<p class="text-success">✅ No shortage flags at this time.</p>';
            return;
        }
        let html='<div class="table-wrapper"><table><thead><tr><th>Area</th><th>Blood Group</th><th>Available Donors</th><th>Status</th></tr></thead><tbody>';
        data.forEach(r=>{
            html+=`<tr><td>${escHtml(r.area_name)}</td><td><span class="blood-badge badge-${bgClass(r.blood_group)}">${escHtml(r.blood_group)}</span></td>
                   <td>${r.count}</td><td><span class="shortage-flag">⚠️ Shortage</span></td></tr>`;
        });
        html+='</tbody></table></div>';
        container.innerHTML=html;
    });

function bgClass(g){const m={'O-':'o-neg','O+':'o-pos','A+':'a-pos','A-':'a-neg','B+':'b-pos','B-':'b-neg','AB+':'ab-pos','AB-':'ab-neg'};return m[g]||'default';}
function escHtml(s){const d=document.createElement('div');d.textContent=s||'';return d.innerHTML;}
</script>
<?php require __DIR__ . '/../../includes/footer.php'; ?>
