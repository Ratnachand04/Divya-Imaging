// Tailwind configuration
window.tailwind = window.tailwind || {};
window.tailwind.config = {
  darkMode: "class",
  theme: {
    extend: {
      colors: {
        "primary": "#e91e63", // Vibrant Pink
        "on-primary": "#ffffff",
        "secondary": "#5c47e5", // Deep Purple/Blue from reference
        "tertiary": "#10b981", // Emerald/Teal from reference
        "surface-container-lowest": "#ffffff",
        "surface-bright": "#fdf4f7", // Slight pinkish tint for background
        "background": "#fdf4f7",
        "outline": "#70787d",
        "surface-container": "#fce4ec", // Very light pink
        "on-surface": "#191c1e",
        "on-surface-variant": "#40484c"
      },
      fontFamily: {
        "headline": ["Manrope"],
        "body": ["Inter"],
        "label": ["Inter"]
      },
      borderRadius: {"DEFAULT": "0.125rem", "lg": "0.25rem", "xl": "0.5rem", "full": "0.75rem"},
    },
  },
};

// Dynamic clock update for the new Tailwind based HTML
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
    hours = hours ? hours : 12; // the hour '0' should be '12'
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
