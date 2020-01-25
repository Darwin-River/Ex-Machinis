export function formatDate(dateString) {
    let dateTimeParts= dateString.split(/[- :]/); // regular expression split that creates array with: year, month, day, hour, minutes, seconds values
    dateTimeParts[1]--; // monthIndex begins with 0 for January and ends with 11 for December so we need to decrement by one
    return  new Date(...dateTimeParts); // our Date object

}