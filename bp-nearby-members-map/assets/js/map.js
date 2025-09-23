jQuery(document).ready(function($){
    var map = L.map('bp-nearby-members-map').setView([20, 0], 2);

    // Add OpenStreetMap tiles
    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
        attribution: '© OpenStreetMap contributors'
    }).addTo(map);

    // Fetch members
    $.get(bpNMMap.ajax_url, { action: 'bp_nearby_members_map' }, function(data){
        data.forEach(function(member){
            var marker = L.marker([member.lat, member.lng]).addTo(map);
            marker.bindPopup('<a href="'+member.profile_url+'">'+member.name+'</a>');
        });
    });
});
