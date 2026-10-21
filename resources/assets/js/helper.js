export function formatDate(dateString) {
    let dateTimeParts= dateString.split(/[- :]/); // regular expression split that creates array with: year, month, day, hour, minutes, seconds values
    dateTimeParts[1]--; // monthIndex begins with 0 for January and ends with 11 for December so we need to decrement by one
    return  new Date(...dateTimeParts); // our Date object

}

// Formats a "YYYY-MM-DD HH:MM:SS" string as "MM/DD/YYYY h:mm:ss A" (local time),
// the format the tables used with vue-moment.
export function formatDateTime(dateString) {
    const d = formatDate(dateString);
    const pad = n => String(n).padStart(2, '0');
    const hours = d.getHours() % 12 || 12;
    const ampm = d.getHours() < 12 ? 'AM' : 'PM';
    return `${pad(d.getMonth() + 1)}/${pad(d.getDate())}/${d.getFullYear()} ${hours}:${pad(d.getMinutes())}:${pad(d.getSeconds())} ${ampm}`;
}
