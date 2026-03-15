/* Camp Availability Integration - Admin JavaScript */

function asCaiAdminApp() {
	return {
		stats: {
			active_reservations: 0,
			reserved_products: 0,
			expired_today: 0,
			system_healthy: true
		},
		
		init() {
			this.loadStats();
			this.initChart();
			setInterval(() => this.loadStats(), 30000); // Refresh every 30s
		},
		
		async loadStats() {
			try {
				const response = await fetch(asCaiAdmin.ajaxUrl, {
					method: 'POST',
					headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
					body: new URLSearchParams({
						action: 'as_cai_get_stats',
						nonce: asCaiAdmin.nonce
					})
				});
				
				const data = await response.json();
				if (data.success) {
					this.stats = data.data;
				}
			} catch (error) {
				console.error('Failed to load stats:', error);
			}
		},
		
		async clearAllReservations() {
			if (!confirm(asCaiAdmin.i18n.confirm_clear)) {
				return;
			}
			
			try {
				const response = await fetch(asCaiAdmin.ajaxUrl, {
					method: 'POST',
					headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
					body: new URLSearchParams({
						action: 'as_cai_clear_reservations',
						nonce: asCaiAdmin.nonce
					})
				});
				
				const data = await response.json();
				if (data.success) {
					alert(asCaiAdmin.i18n.cleared);
					this.loadStats();
					if (typeof location !== 'undefined') {
						location.reload();
					}
				} else {
					alert(data.data.message || asCaiAdmin.i18n.error);
				}
			} catch (error) {
				alert(asCaiAdmin.i18n.error);
				console.error('Failed to clear reservations:', error);
			}
		},
		
		refreshReservations() {
			if (typeof location !== 'undefined') {
				location.reload();
			}
		},
		
		loadRecentActivity() {
			// Placeholder for recent activity
			this.activities = [
				{ id: 1, message: 'Reservation created for Product #123', time: '2 minutes ago' },
				{ id: 2, message: 'Reservation expired for Customer XYZ', time: '15 minutes ago' }
			];
		},
		
		initChart() {
			const canvas = document.getElementById('reservationsChart');
			if (!canvas || typeof Chart === 'undefined') return;
			
			const ctx = canvas.getContext('2d');
			new Chart(ctx, {
				type: 'line',
				data: {
					labels: ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'],
					datasets: [{
						label: 'Active Reservations',
						data: [12, 19, 15, 25, 22, 30, 28],
						borderColor: 'rgb(102, 126, 234)',
						backgroundColor: 'rgba(102, 126, 234, 0.1)',
						tension: 0.4,
						fill: true
					}, {
						label: 'Expired',
						data: [5, 8, 7, 10, 9, 12, 11],
						borderColor: 'rgb(245, 87, 108)',
						backgroundColor: 'rgba(245, 87, 108, 0.1)',
						tension: 0.4,
						fill: true
					}]
				},
				options: {
					responsive: true,
					maintainAspectRatio: true,
					plugins: {
						legend: {
							position: 'bottom'
						}
					},
					scales: {
						y: {
							beginAtZero: true
						}
					}
				}
			});
		}
	};
}

// Make function globally available for Alpine.js
window.asCaiAdminApp = asCaiAdminApp;

// Initialize when Alpine is ready
document.addEventListener('alpine:init', () => {
	console.log('Ayonto Camp Availability Admin initialized');
});
