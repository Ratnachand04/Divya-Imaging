// Dynamic clock update for the SuperAdmin Dashboard
function updateDashboardClock() {
    const timeEl = document.getElementById('sa-dash-time');
    const dateEl = document.getElementById('sa-dash-date');

    if(!timeEl && !dateEl) return;

    const now = new Date();
    
    // Format Time: 02:41 PM
    let hours = now.getHours();
    const minutes = now.getMinutes().toString().padStart(2, '0');
    const ampm = hours >= 12 ? 'PM' : 'AM';
    hours = hours % 12;
    hours = hours ? hours : 12;
    const timeString = `${hours.toString().padStart(2, '0')}:${minutes} ${ampm}`;
    
    // Format Date: THU, 26 MAR, 2026
    const days = ['SUN','MON','TUE','WED','THU','FRI','SAT'];
    const months = ['JAN','FEB','MAR','APR','MAY','JUN','JUL','AUG','SEP','OCT','NOV','DEC'];
    const dateString = `${days[now.getDay()]}, ${now.getDate().toString().padStart(2, '0')} ${months[now.getMonth()]}, ${now.getFullYear()}`;
    
    if(timeEl) timeEl.textContent = timeString;
    if(dateEl) dateEl.textContent = dateString;
}

setInterval(updateDashboardClock, 1000);
document.addEventListener('DOMContentLoaded', updateDashboardClock);
