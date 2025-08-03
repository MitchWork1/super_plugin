<?php
/*
Plugin Name: AA Super Plugin
Description: :)
Version: 1.0
Author: Mitchell Konemann
*/
//add_action('wp_enqueue_scripts', 'pcp_enqueue_assets');
//add_action('admin_enqueue_scripts', 'pcp_enqueue_assets');
add_filter('the_posts', 'detect_custom_calendar_shortcode');

function detect_custom_calendar_shortcode($posts) {
    if (empty($posts)) return $posts;

    $found = false;
    foreach ($posts as $post) {
        if (has_shortcode($post->post_content, 'custom_calendar')) {
            $found = true;
            break;
        }
    }

    if ($found) {
        add_action('wp_enqueue_scripts', 'enqueue_custom_calendar_assets');
        add_action('admin_enqueue_scripts', 'enqueue_custom_calendar_assets');
    }

    return $posts;
}

function enqueue_custom_calendar_assets() {
    wp_enqueue_style('pcp-style', plugin_dir_url(__FILE__) . 'calendar_style.css');

    wp_enqueue_script('pcp-calendar', plugin_dir_url(__FILE__) . 'calendar.js', ['jquery'], null, true);

    wp_enqueue_script('paystack', 'https://js.paystack.co/v1/inline.js', [], null, true);

    wp_localize_script('pcp-calendar', 'pcp_ajax', [
        'ajax_url' => admin_url('admin-ajax.php'),
        'nonce'    => wp_create_nonce('pcp_nonce')
    ]);
}

add_shortcode('custom_calendar', 'pcp_custom_calendar_shortcode');

function pcp_custom_calendar_shortcode() {
    global $wpdb;

    $providers = $wpdb->get_results("SELECT * FROM {$wpdb->prefix}provider_sites");     

    ob_start(); ?>

    <div id="calendar-container">
        <label for="provider-select">Select Provider:</label>
        <select id="provider-select">
            <option value="">-- Select Provider --</option>
            <?php foreach ($providers as $provider): ?>
                <option value="<?= esc_attr($provider->provider_id); ?>">
                    <?= esc_html($provider->provider_name); ?>
                </option>
            <?php endforeach; ?>
        </select>

        <label for="service-select">Select Service:</label>
        <select id="service-select" disabled>
            <option value="">-- Select Provider First --</option>
        </select>

         <div id="calendar-controls" style="display: flex; align-items: center; justify-content: flex-end; gap: 10px; margin: 10px 0;">
            <button id="prev-month" disabled>&laquo; Previous</button>
            <span id="calendar-title" style="font-weight: bold;"></span>
            <button id="next-month">Next &raquo;</button>
        </div>

        <div id="my-calendar">
            <div class="calendar-header">
                <div>Monday</div>
                <div>Tuesday</div>
                <div>Wednesday</div>
                <div>Thursday</div>
                <div>Friday</div>
                <div>Saturday</div>
                <div>Sunday</div>
            </div>
            <div class="calendar-body" id="calendar-body">
                <!-- Days + slots will appear here -->
            </div>
        </div>
        <div id="booking_summary" style="display: none;">
            <h2>Booking Summary</h2>    
        </div>
        <div id="booking_total" class="booking-total" style="display:none;">
            <table id="booking_total_table" class="booking-total-table"> 
                <tr id="booking_total_table_column">
                    <td id="total_label" class="total-label">Total Cost:</td>
                    <td id="total-amount" class="total-amount">R0.00 </td>
                </tr>
            </table>
        </div>
        <div id="checkout_section" style="text-align: center; margin-top: 10px; display: none;">
            <button id="checkout_button" class="checkout-btn">Checkout</button>
        </div>
    </div>
    <div id="client-info-modal" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background-color:rgba(0,0,0,0.6); z-index:9999; align-items:center; justify-content:center;">
        <div style="background:#fff; padding:20px; border-radius:8px; max-width:300px; width:90%; box-shadow:0 4px 10px rgba(0,0,0,0.2);">
            <h3 style="margin-top:0;">Enter Details to continue</h3>
            <label>Name:</label><br>
            <input type="text" id="client-name" style="width:100%; padding:8px; margin-bottom:10px;"><br>
            <label>Email:</label><br>
            <input type="email" id="client-email" style="width:100%; padding:8px;"><br><br>
            <button id="submit-client-info" style="padding:8px 12px;">Continue</button>
            <button id="cancel-client-info" style="padding:8px 12px; background:#ccc; margin-left:10px;">Cancel</button>
        </div>
    </div>
    

    <?php
    return ob_get_clean();
}

add_action('wp_ajax_get_services', 'pcp_ajax_get_services');
add_action('wp_ajax_nopriv_get_services', 'pcp_ajax_get_services');
function pcp_ajax_get_services() {
    global $wpdb;

    $provider_id = isset($_GET['provider']) ? intval($_GET['provider']) : 0;
    if (!$provider_id) wp_send_json([]);

    $services_table = $wpdb->prefix . 'services';
    $services = $wpdb->get_results($wpdb->prepare(
        "SELECT service_id, service_name, service_cost FROM $services_table WHERE provider_id = %d",
        $provider_id
    ));

    wp_send_json($services);
}

add_action('wp_ajax_get_availability', 'pcp_ajax_get_availability');
add_action('wp_ajax_nopriv_get_availability', 'pcp_ajax_get_availability');
function pcp_ajax_get_availability() {
    global $wpdb;

    $availability_table = $wpdb->prefix . 'availability';

    $wpdb->query("
        UPDATE $availability_table
        SET status = 'a', hold_until = NULL, session_id = NULL
        WHERE status = 'p' AND hold_until < NOW()
    ");

    $service_id = isset($_GET['service_id']) ? intval($_GET['service_id']) : 0;
    if (!$service_id) wp_send_json([]);

    $availability_table = $wpdb->prefix . 'availability';

    $results = $wpdb->get_results($wpdb->prepare("
        SELECT 
            availability_id,
            available_date,
            time_slot,
            time_slot_length_min,
            status
        FROM $availability_table
        WHERE service_id = %d
            AND available_date >= CURDATE()
        ORDER BY available_date, time_slot
    ", $service_id));

    $availability = [];

    foreach ($results as $row) {
        $date = substr($row->available_date, 0, 10); // Ensure format: YYYY-MM-DD

        if (!isset($availability[$date])) {
            $availability[$date] = [];
        }

        $slot_key = $row->time_slot . '_' . $row->time_slot_length_min;

        if (!isset($availability[$date][$slot_key])) {
            $availability[$date][$slot_key] = [
                'time' => substr($row->time_slot, 0, 5),
                'length_min' => intval($row->time_slot_length_min),
                'spots_total' => 0,
                'spots_booked' => 0,
                'spots' => []
            ];
        }

        $availability[$date][$slot_key]['spots_total']++;

        if ($row->status !== 'a') {
            $availability[$date][$slot_key]['spots_booked']++;
        }

         $availability[$date][$slot_key]['spots'][] = [
        'availability_id' => intval($row->availability_id),
        'status'          => $row->status
        ];
    }

    foreach ($availability as $date => &$slots) {
        $slots = array_values($slots);
    }

    wp_send_json($availability);
}

//=======================
//Provider Admin Calendar
//=======================

add_shortcode('provider_admin_custom_calendar', 'provider_admin_custom_calendar');

function provider_admin_custom_calendar() {
    if (!is_user_logged_in()) {
        return '<p>You must be logged in to view this calendar.</p>';
    }

    global $wpdb;

    $current_user = wp_get_current_user();
    $username = $current_user->user_login;

    // Get provider by username
    $provider = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM {$wpdb->prefix}provider_sites WHERE provider_name = %s",
        $username
    ));

    if (!$provider) {
        return '<p>Provider not found for current user.</p>';
    }

    $provider_id = $provider->provider_id;

    // Get services for provider
    $services = $wpdb->get_results($wpdb->prepare(
        "SELECT * FROM {$wpdb->prefix}services WHERE provider_id = %d",
        $provider_id
    ));

    if (!$services) {
        return '<p>No services found for this provider.</p>';
    }

    ob_start();
    ?>

    <div id="calendar-container">
        <label for="service-select">Select Service:</label>
        <select id="service-select">
            <option value="">-- Select Service --</option>
            <?php foreach ($services as $service): ?>
                <option value="<?= esc_attr($service->service_id); ?>">
                    <?= esc_html($service->service_name); ?>
                </option>
            <?php endforeach; ?>
        </select>

        <div id="calendar-controls" style="display: flex; align-items: center; justify-content: flex-end; gap: 10px; margin: 10px 0;">
            <button id="prev-month" disabled>&laquo; Previous</button>
            <span id="calendar-title" style="font-weight: bold;"></span>
            <button id="next-month">Next &raquo;</button>
        </div>

        <div id="my-calendar">
            <div class="calendar-header">
                <div>Monday</div><div>Tuesday</div><div>Wednesday</div><div>Thursday</div>
                <div>Friday</div><div>Saturday</div><div>Sunday</div>
            </div>
            <div class="calendar-body" id="calendar-body"></div>
        </div>
    </div>

    <script>
    document.addEventListener('DOMContentLoaded', function () {
        const admin_nonce = {
            ajax_url: "<?= esc_url(admin_url('admin-ajax.php')) ?>",
            nonce: "<?= esc_js(wp_create_nonce('admin_nonce')) ?>"
        };
        const serviceSelect = document.getElementById('service-select');
        const calendarTitle = document.getElementById('calendar-title');
        const prevBtn = document.getElementById('prev-month');
        const nextBtn = document.getElementById('next-month');

        let currentYear = new Date().getFullYear();
        let currentMonth = new Date().getMonth();
        const today = new Date();

        prevBtn.addEventListener('click', () => {
            if (currentMonth === 0) {
                currentYear--;
                currentMonth = 11;
            } else {
                currentMonth--;
            }
            updateCalendar();
        });

        nextBtn.addEventListener('click', () => {
            if (currentMonth === 11) {
                currentYear++;
                currentMonth = 0;
            } else {
                currentMonth++;
            }
            updateCalendar();
        });

        function updateCalendar() {
            const selectedService = serviceSelect.value;
            if (!selectedService) {
                generateCalendar(currentYear, currentMonth, {});
                updateControls();
                return;
            }

            fetch(`<?= admin_url('admin-ajax.php'); ?>?action=get_availability&service_id=${encodeURIComponent(selectedService)}`)
                .then(res => res.json())
                .then(data => {
                    generateCalendar(currentYear, currentMonth, data);
                    updateControls();
                });
        }

        function updateControls() {
            calendarTitle.textContent = new Date(currentYear, currentMonth).toLocaleString('default', { month: 'long', year: 'numeric' });
            prevBtn.disabled = currentYear === today.getFullYear() && currentMonth === today.getMonth();
        }

        function generateCalendar(year, month, slotsData = {}) {
            const calendarBody = document.getElementById('calendar-body');
            calendarBody.innerHTML = '';

            let firstDay = new Date(year, month, 1).getDay();
            firstDay = firstDay === 0 ? 6 : firstDay - 1;

            const daysInMonth = new Date(year, month + 1, 0).getDate();

            for (let i = 0; i < firstDay; i++) {
                const empty = document.createElement('div');
                empty.className = 'day-cell empty';
                calendarBody.appendChild(empty);
            }

            for (let day = 1; day <= daysInMonth; day++) {
                const dateKey = `${year}-${String(month + 1).padStart(2, '0')}-${String(day).padStart(2, '0')}`;
                const slotsForDay = slotsData[dateKey] || [];

                const cell = document.createElement('div');
                cell.className = 'day-cell';
                if (year === today.getFullYear() && month === today.getMonth() && day === today.getDate()) {
                    cell.classList.add('today');
                }

                const number = document.createElement('div');
                number.className = 'day-number';
                number.textContent = day;
                cell.appendChild(number);

                slotsForDay.forEach(slot => {
                    const wrapper = document.createElement('div');
                    wrapper.className = "slots-wrapper";

                    const table = document.createElement('table');
                    table.className = 'slots-table';

                    const tr = document.createElement('tr');
                    tr.style.position = 'relative';

                    const startTime = slot.time;
                    const start = new Date(`${dateKey}T${startTime}`);
                    const end = new Date(start.getTime() + slot.length_min * 60000);
                    const endTimeStr = end.toTimeString().slice(0, 5);

                    const timeTd = document.createElement('td');
                    timeTd.textContent = `${startTime} - ${endTimeStr}`;
                    timeTd.className = 'hover-target time-cell';
                    tr.appendChild(timeTd);

                    const spotsTd = document.createElement("td");
                    spotsTd.classList.add("spots", "hover-target");
                    const spotsAvailable = slot.spots_total - slot.spots_booked;
                    spotsTd.textContent = `${spotsAvailable} / ${slot.spots_total}`;
                    tr.appendChild(spotsTd);

                    if (spotsAvailable > 0) {
                        const btn = document.createElement("button");
                        btn.classList.add("book-btn");
                        btn.dataset.spots_info = JSON.stringify(slot.spots);
                        btn.textContent = "Book Now";
                        btn.style.position = "absolute";
                        btn.style.top = "0";
                        btn.style.left = "0";
                        btn.style.width = "100%";
                        btn.style.height = "100%";
                        btn.style.backgroundColor = "#4caf50";
                        btn.style.color = "white";
                        btn.style.border = "none";
                        btn.style.borderRadius = "4px";
                        btn.style.fontSize = "11px";
                        btn.style.display = "flex";
                        btn.style.justifyContent = "center";
                        btn.style.alignItems = "center";
                        btn.style.opacity = "0";
                        btn.style.pointerEvents = "none";
                        btn.style.transition = "opacity 0.2s ease-in-out";
                        btn.style.zIndex = "10";
                        btn.style.cursor = "pointer";

                        btn.addEventListener("click", (e) => {
                            const spotsInfoStr = e.target.dataset.spots_info;
                            const all_spots_info = JSON.parse(spotsInfoStr);
                            let selectedAvailabilityId = null;

                            for (const spot of all_spots_info) {
                                if (spot.status === "a") {
                                    selectedAvailabilityId = spot.availability_id;
                                    break;
                                }
                            }

                            if (selectedAvailabilityId) {
                                const confirmed = confirm("Are you sure you wish to book this spot?");
                                if (!confirmed) {
                                    return;
                                }

                                fetch(admin_nonce.ajax_url, {
                                    method: "POST",
                                    headers: {
                                    "Content-Type": "application/x-www-form-urlencoded",
                                    },
                                    body: new URLSearchParams({
                                    action: "admin_book_spot_available",
                                    availability_id: selectedAvailabilityId,
                                    security: admin_nonce.nonce,
                                    }),
                                })
                                    .then((res) => res.json())
                                    .then((data) => {
                                    if (data.success) {
                                        btn.textContent = "Booked!";
                                        btn.classList.add("booked-wave");
                                        btn.disabled = true;

                                        btn.style.opacity = "1";
                                        btn.style.pointerEvents = "auto";

                                        setTimeout(() => {
                                        btn.classList.remove("booked-wave");
                                        btn.disabled = false;
                                        updateCalendar();
                                        }, 1000);
                                    } else {
                                        alert("Error: " + data.data);
                                    }
                                    });
                                
                            } else {
                                alert("No available spot to book.");
                            }
                        });
                        tr.appendChild(btn);
                    }                    
                    table.appendChild(tr);
                    wrapper.appendChild(table);
                    cell.appendChild(wrapper);
                });

                calendarBody.appendChild(cell);
            }
        }

        serviceSelect.addEventListener('change', updateCalendar);

        updateCalendar();
    });
    </script>

    <style>
        #calendar-container {
  max-width: 1050px;
  margin: 20px auto;
  font-family: Arial, sans-serif;
    }

    label {
  display: inline-block;
  margin: 0 10px 10px 0;
  font-weight: bold;
    }

    select {
  margin-right: 20px;
  padding: 5px;
  min-width: 180px;
    }

    #my-calendar {
  border: 1px solid #ccc;
  box-shadow: 0 0 8px rgba(0,0,0,0.1);
  user-select: none;
    }

    .calendar-header {
  display: grid;
  grid-template-columns: repeat(7, 1fr);
  background-color: #f5f5f5;
  border-bottom: 1px solid #ccc;
  text-align: center;
  font-weight: bold;
  font-size: 14px;
  padding: 10px 0;
    }

    .calendar-header > div {
  border-right: 1px solid #ccc;
    }

    .calendar-header > div:last-child {
  border-right: none;
    }

    .calendar-body {
  display: grid;
  grid-template-columns: repeat(7, 1fr);
  background-color: #fff;
  min-height: 300px;
    }

    .day-cell {
  border: 1px solid #eee;
  min-height: 140px;
  padding: 8px;
  font-size: 13px;
  display: flex;
  flex-direction: column;
  justify-content: flex-start;
  position: relative;
  overflow: hidden;
  box-sizing: border-box;
  white-space: nowrap;
    }

    .day-cell.today {
  background-color: #fff9e6;
  border: 1px solid #ffd700;
    }

    .day-cell.empty {
  background: #f9f9f9;
  border: none;
    }

    .day-number {
  font-weight: bold;
  margin-bottom: 6px;
    }

    .slots-wrapper {
  border: 2px solid #07bcf3;
  border-radius: 8px;
  margin-top: 2px;
  position: realative;
  overflow: hidden;
    }

    .slots-wrapper:hover .book-btn {
    opacity: 1 !important;
    pointer-events: auto !important;
}

    .slots-table {
  width: 100%;
  border-collapse: collapse;
  font-size: 12px;
    }

    .slots-table td {
  padding: 4px 6px;
  border: none;
  position: relative;
  cursor: pointer;
    }

    .slots-table td.time-cell {
  padding: 4px 6px;
  border: none;
  position: relative;
  cursor: pointer;
  background-color: #90d3ff;
    }

    .slots-table td.spots {
  padding: 4px 6px;
  border: 1px;
  position: relative;
  cursor: pointer;
  background-color: #67c2ff;
    }
    </style>

    <?php
    return ob_get_clean();
}

add_action('wp_ajax_admin_book_spot_available', 'admin_book_spot_available');

function admin_book_spot_available(){
    global $wpdb;

    check_ajax_referer('admin_nonce', 'security');
    $availability_id = intval($_POST['availability_id']);
    if (!$availability_id) {
        wp_send_json_error('Missing spot ID or session ID');
    }

    $availability_table = $wpdb->prefix . 'availability';

    $updated = $wpdb->query(
        $wpdb->prepare("
            UPDATE $availability_table
            SET status = 'b'
            WHERE availability_id = %d AND status = 'a'
        ", $availability_id)
    );    

    if ($updated === false) {
        wp_send_json_error('Database error');
    }

    if ($updated === 0) {
        wp_send_json_error('Spot not available or already booked');
    }

    wp_send_json_success('Spot booked');
}

//====
//AJAX
//====

add_action('wp_ajax_pcp_book_spot_in_avail', 'pcp_book_spot_in_avail');
add_action('wp_ajax_nopriv_pcp_book_spot_in_avail', 'pcp_book_spot_in_avail');

add_action('wp_ajax_pcp_release_spot', 'pcp_release_spot');
add_action('wp_ajax_nopriv_pcp_release_spot', 'pcp_release_spot');

//Book spot is not a proper booking just desegnating id to availability db
function pcp_book_spot_in_avail() {
    global $wpdb;

    check_ajax_referer('pcp_nonce', 'security');

    $availability_id = intval($_POST['availability_id']);
    $session_id = sanitize_text_field($_POST['session_id'] ?? '');
    if (!$session_id) {
        wp_send_json_error('Missing session ID');
    }

    if (!$availability_id) {
        wp_send_json_error('Missing spot ID');
    }

    $availability_table = $wpdb->prefix . 'availability';

    $updated = $wpdb->query(
        $wpdb->prepare("
            UPDATE $availability_table
            SET status = 'p',
            hold_until = DATE_ADD(NOW(), INTERVAL 15 MINUTE),
            session_id = %s
            WHERE availability_id = %d AND status = 'a'
        ",$session_id, $availability_id)
    );

    

    if ($updated === false) {
        wp_send_json_error('Database error');
    }

    if ($updated === 0) {
        wp_send_json_error('Spot not available or already booked');
    }
    //Add post update to provider
    update_providers_availability_spots($session_id);
    wp_send_json_success('Spot reserved as pending');
}

function pcp_release_spot() {
    global $wpdb;
    
    check_ajax_referer('pcp_nonce', 'security');

    $availability_id = intval($_POST['availability_id']);
    if (!$availability_id) {
        wp_send_json_error('Missing spot ID');
    }
    $session_id = sanitize_text_field($_POST['session_id'] ?? null);

    $availability_table = $wpdb->prefix . 'availability';

    $updated = $wpdb->query(
        $wpdb->prepare("
            UPDATE $availability_table
            SET status = 'a',
            hold_until = NULL,
            session_id = NULL
            WHERE availability_id = %d AND status = 'p'
        ", $availability_id)
    );

    if ($updated === false) {
        wp_send_json_error('Database error');
    }

    if ($updated === 0) {
        wp_send_json_error('Spot not pending or already released/booked');
    }
    //Add post update to provider
    update_providers_availability_spots($session_id);
    wp_send_json_success('Spot released');
}

add_action('wp_ajax_pcp_release_all_spots', 'pcp_release_all_spots');
add_action('wp_ajax_nopriv_pcp_release_all_spots', 'pcp_release_all_spots');

function pcp_release_all_spots() {
    global $wpdb;

    check_ajax_referer('pcp_nonce', 'security');

    $session_id = sanitize_text_field($_POST['session_id'] ?? '');
    if (!$session_id) {
        wp_send_json_error('Missing session ID');
    }

    $availability_table = $wpdb->prefix . 'availability';    

    $wpdb->query(
        $wpdb->prepare("
            UPDATE $availability_table
            SET status = 'a',
                hold_until = NULL,
                session_id = NULL
            WHERE session_id = %s AND status = 'p'
        ", $session_id)
    );

    //Add post update to provider
    update_providers_availability_spots($session_id);
    wp_send_json_success('Spots released');
}

add_action('wp_ajax_pcp_init_payment', 'pcp_init_payment');
add_action('wp_ajax_nopriv_pcp_init_payment', 'pcp_init_payment');

function pcp_init_payment(){
    global $wpdb;

    check_ajax_referer('pcp_nonce', 'security');

    $session_id = sanitize_text_field($_POST['session_id'] ?? '');
    $customer_name = sanitize_text_field($_POST['customer_name' ?? '']);
    $customer_email = sanitize_text_field($_POST['customer_email' ?? '']);

    if (empty($session_id)) {
        wp_send_json_error('Missing session ID');
    }
    if (empty($customer_name)) {
        wp_send_json_error('Missing customer_name');
    }
    if (empty($customer_email)) {
        wp_send_json_error('Missing customer_email');
    }    

    $paystack_secret = get_var($wpdb->prepare("
    SELECT paystack_api_key_secret
    FROM {$wpdb->prefix}paystack_info
    WHERE id = 1"));

    if(!empty($paystack_secret))
    {       
        //Select (using session_id), availability_id, amount, subaccounts, 

        $results = $wpdb->get_results($wpdb->prepare("
        SELECT 
            a.availability_id,
            s.service_cost,
            p.paystack_subaccount
        FROM 
            {$wpdb->prefix}availability a
        JOIN 
            {$wpdb->prefix}services s ON a.service_id = s.service_id
        JOIN
            {$wpdb->prefix}provider_sites p ON s.provider_id = p.provider_id
        WHERE 
            a.session_id = %s
        ", $session_id), ARRAY_A);

        if (!empty($results)) {
            $provider_data = [];
            $total_cost = 0;

            foreach ($results as $row) {
                $paystack_subaccount = $row['paystack_subaccount'];
                $cost = floatval($row['service_cost']);
                $availability_id = intval($row['availability_id']);

                $already_booked = $wpdb->get_var($wpdb->prepare(
                    "SELECT COUNT(*) FROM {$wpdb->prefix}bookings WHERE availability_id = %d",
                    $availability_id
                ));

                if (!$already_booked) {
                    $wpdb->insert(
                        "{$wpdb->prefix}bookings",
                        [
                            'availability_id' => $availability_id,
                            'customer_name' => $customer_name,
                            'customer_email' => $customer_email,
                            'booked_by_main'  => 1
                        ],
                        ['%d', '%s', '%s', '%d']
                    );
                }

                if (!isset($provider_data[$paystack_subaccount])) {
                    $provider_data[$paystack_subaccount] = [
                        'paystack_subaccount' => $paystack_subaccount,
                        'provider_total' => 0,
                        'all_availability_ids' => []
                    ];
                }

                $provider_data[$paystack_subaccount]['provider_total'] += $cost;
                $provider_data[$paystack_subaccount]['all_availability_ids'][] = $availability_id;
                $total_cost += $cost;
            }

            $split = build_split($provider_data, $total_cost);


            $url = "https://api.paystack.co/transaction/initialize";

            $fields = [
                'email' => $customer_email,
                'amount' => $total_cost,
                'callback_url' => "https://hello.pstk.xyz/callback",
                'reference' => $session_id,
                'split' => [
                    'type' => 'flat',
                    'bearer_type' => 'account',
                    'subaccounts' => $split
                ]
            ];

            $fields_string = http_build_query($fields);

            //open connection
            $ch = curl_init();
    
            //set the url, number of POST vars, POST data
            curl_setopt($ch,CURLOPT_URL, $url);
            curl_setopt($ch,CURLOPT_POST, true);
            curl_setopt($ch,CURLOPT_POSTFIELDS, $fields_string);
            curl_setopt($ch, CURLOPT_HTTPHEADER, array(
                "Authorization: Bearer $paystack_secret",
                "Cache-Control: no-cache",
            ));
    
            //So that curl_exec returns the contents of the cURL; rather than echoing it
            curl_setopt($ch,CURLOPT_RETURNTRANSFER, true); 
    
            //execute post
            $response = curl_exec($ch);
            curl_close($ch);

            if (isset($response['status']) && $response['status'] === true) {
                $authorization_url = $response['data']['authorization_url'];

                //Make sure pending payment availability spot doesn't become available
                $updated = $wpdb->query(
                    $wpdb->prepare("
                    UPDATE {$wpdb->prefix}availability
                    SET hold_until = NULL
                    WHERE session_id = %d
                ", $session_id)                            
                );
                wp_send_json_success([
                    'redirect_url' => $authorization_url        
                ]);
            } 
            else {
                wp_send_json_error([
                    'message' => 'Payment initialization failed',
                    'response' => $response
                ]);
            }
        }
    }
    else{
        wp_send_json_error('No paystack secret key');
    }  
}

function build_split($provider_data, $total_cost){
    $split = [];
    $operational_cost = $total_cost * 100 * 0.01; //1%, *100 for kobo

    //Amounts must be in kobo so *100

    foreach ($provider_data as $data) {
        $share = intval($data['provider_total'] * 100);

        $subaccounts[] = [
            'subaccount' => $data['paystack_subaccount'],
            'share' => $share
        ];
    }    

    $subaccounts[] = [
        'subaccount' => HARDCODE, //MY_PACKSTACK CODE
        'share' => $operational_cost //MY SHARE 
    ];

    return $split;
}

//========
//Paystack
//========

add_action('rest_api_init', function () {
    register_rest_route('pcp/v1', '/paystack/webhook', array(
        'methods' => 'POST',
        'callback' => 'paystack_webhook',
        'permission_callback' => '__return_true', // Make public for Paystack
    ));
});

function paystack_webhook(){

    $paystack_secret = get_var($wpdb->prepare("
        SELECT paystack_api_key_secret
        FROM {$wpdb->prefix}paystack_info
        WHERE id = 1"
    ));

    if ((strtoupper($_SERVER['REQUEST_METHOD']) != 'POST' ) || !array_key_exists('HTTP_X_PAYSTACK_SIGNATURE', $_SERVER) ) 
    {
        exit();
    }
        

    // Retrieve the request's body
    $input = @file_get_contents("php://input");

    // validate event do all at once to avoid timing attack
    if($_SERVER['HTTP_X_PAYSTACK_SIGNATURE'] !== hash_hmac('sha512', $input, $paystack_secret))
    {
        exit();
    }        

    http_response_code(200);

    // parse event (which is json string) as object
    // Do something - that will not take long - with $event
    $event = json_decode($input);
    $reference = $event->data->reference;
    if ($event->event === 'charge.success' || $event->event === 'transfer.success') {       
        //Add api
        $wpdb->query(
            $wpdb->prepare("
                UPDATE $availability_table
                SET status = 'b', hold_until = NULL
                WHERE status = 'p' AND session_id = %s
            ", $reference)
        );
        update_providers_availability_spots($session_id);
    }
    else{
        $wpdb->query(
            $wpdb->prepare("
                DELETE FROM {$wpdb->prefix}bookings
                WHERE availability_id IN (
                    SELECT availability_id
                    FROM {$wpdb->prefix}availability
                    WHERE session_id = %s
                )
            ", $reference)
        );
        $wpdb->query(
            $wpdb->prepare("
                UPDATE $availability_table
                SET status = 'a',
                    hold_until = NULL,
                    session_id = NULL
                WHERE session_id = %s AND status = 'p'
            ", $reference)
        );
        update_providers_availability_spots($session_id);
    }

    exit();
}

add_action('wp_ajax_generate_session_id', 'generate_session_id');
add_action('wp_ajax_nopriv_generate_session_id', 'generate_session_id');

function generate_session_id() {
    global $wpdb;

    $table = $wpdb->prefix . 'availability'; 

    $max_attempts = 5;
    for ($i = 0; $i < $max_attempts; $i++) {

        $session_id = uniqid('', true);

        $count = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM $table WHERE session_id = %s",
            $session_id
        ));

        if ($count == 0) {
            wp_send_json_success(['session_id' => $session_id]);
        }
    }
    wp_send_json_error('Could not generate unique session ID');
}

//=============
//Merged Plugin 
//=============

register_activation_hook(__FILE__, 'create_tables');
function create_tables() {
    global $wpdb;
    $charset_collate = $wpdb->get_charset_collate();

    $paystack_info_table = $wpdb->prefix . 'paystack_info';
    $sql0 = "CREATE TABLE IF NOT EXISTS $paystack_info_table (
        id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,        
        paystack_api_key_secret VARCHAR(255) NOT NULL,
        paystack_api_key_public VARCHAR(255),
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id)
    ) $charset_collate;";

    $provider_sites_table = $wpdb->prefix . 'provider_sites';
    $sql1 = "CREATE TABLE IF NOT EXISTS $provider_sites_table (
        provider_id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
        provider_name VARCHAR(200) NOT NULL,
        api_key VARCHAR(255) NOT NULL,
        hmac_secret VARCHAR(255),
        paystack_subaccount VARCHAR(255),
        base_api_url TEXT NOT NULL,
        active BOOLEAN DEFAULT 1,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (provider_id)
    ) $charset_collate;";

    $services_table = $wpdb->prefix . 'services';
    $sql2 = "CREATE TABLE IF NOT EXISTS $services_table (
        service_id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
        provider_id BIGINT(20) UNSIGNED NOT NULL,
        service_name VARCHAR(100) NOT NULL,
        service_cost DECIMAL(10,2) NOT NULL,
        max_spots SMALLINT UNSIGNED NOT NULL DEFAULT 8,
        PRIMARY KEY (service_id),
        FOREIGN KEY (provider_id) REFERENCES $provider_sites_table(provider_id) ON DELETE CASCADE
    ) $charset_collate;";

    $availability_table = $wpdb->prefix . 'availability';
    $sql3 = "CREATE TABLE IF NOT EXISTS $availability_table (
        availability_id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
        service_id BIGINT(20) UNSIGNED NOT NULL,
        provider_db_id BIGINT(20) UNSIGNED,
        available_date DATE NOT NULL,
        time_slot TIME,
        time_slot_length_min INT,
        status ENUM('a','p','b') DEFAULT 'a',
        hold_until DATETIME NULL,
        session_id VARCHAR(64),
        PRIMARY KEY (availability_id),
        FOREIGN KEY (service_id) REFERENCES $services_table(service_id) ON DELETE CASCADE
    ) $charset_collate;";

    $bookings_table = $wpdb->prefix . 'bookings';
    $sql4 = "CREATE TABLE IF NOT EXISTS $bookings_table (
        booking_id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
        availability_id BIGINT(20) UNSIGNED NOT NULL,
        booked_by_main  BOOLEAN NOT NULL,
        customer_name VARCHAR(100),
        customer_email VARCHAR(100),
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (booking_id),
        FOREIGN KEY (availability_id) REFERENCES $availability_table(availability_id) ON DELETE CASCADE
    ) $charset_collate;";
        

    require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
    dbDelta($sql0);
    dbDelta($sql1);
    dbDelta($sql2);
    dbDelta($sql3);
    dbDelta($sql4);
}

add_action('init', 'register_provider_role');
function register_provider_role() {
    add_role('provider', 'Provider', [
        'read' => true,
        'edit_posts' => false,
        'delete_posts' => false,
    ]);
}

//====================4
//Admin page for Admin
//====================

add_action('admin_menu', 'admin_menu_provider');
function admin_menu_provider() {
    add_menu_page('Provider Manager', 'Provider Manager', 'manage_options', 'api-provider-manager', 'provider_manager_page');
}

function provider_manager_page() {
    global $wpdb;
    $table = $wpdb->prefix . 'provider_sites'; // match create_tables()

    if ($wpdb->get_var("SHOW TABLES LIKE '$table'") != $table) {
        create_tables();
    }

    if (isset($_POST['add_provider'])) {
        $provider_name = sanitize_text_field($_POST['provider_name']);
        $base_api_url = esc_url_raw($_POST['base_api_url']);
        $paystack_subaccount = sanitize_text_field($_POST['paystack_subaccount']);
        $api_key = create_api_key();
        $hmac_secret = create_hmac_secret();

        $wpdb->insert($table, [
            'provider_name' => $provider_name,
            'api_key' => $api_key,
            'hmac_secret' => $hmac_secret,
            'base_api_url' => $base_api_url,
            'paystack_subaccount' => $paystack_subaccount,
        ]);

        echo '<div class="updated"><p>Provider added.</p></div>';
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (isset($_POST['api_reroll_key'], $_POST['provider_id'])) {
            $provider_id = intval($_POST['provider_id']);
            $new_key = create_api_key();
            $wpdb->update($table, ['api_key' => $new_key], ['provider_id' => $provider_id]);
            echo '<div class="updated"><p>API key rerolled for provider ID: ' . esc_html($provider_id) . '</p></div>';
        }
        if (isset($_POST['api_reroll_hmac'], $_POST['provider_id'])) {
            $provider_id = intval($_POST['provider_id']);
            $new_secret = create_hmac_secret();
            $wpdb->update($table, ['hmac_secret' => $new_secret], ['provider_id' => $provider_id]);
            echo '<div class="updated"><p>HMAC secret rerolled for provider ID: ' . esc_html($provider_id) . '</p></div>';
        }
    }

    $providers = $wpdb->get_results("SELECT * FROM $table");

    $eyeSVG = '<svg viewBox="0 0 24 24" class="toggle-visibility" xmlns="http://www.w3.org/2000/svg"><path d="M12 5c-7 0-10 7-10 7s3 7 10 7 10-7 10-7-3-7-10-7zm0 12a5 5 0 110-10 5 5 0 010 10z"/><circle cx="12" cy="12" r="2.5"/></svg>';

    echo '<div class="wrap"><h1>Provider Manager</h1>';

    echo '<form method="post">
        <table class="form-table">
            <tr><th><label for="provider_name">Provider Name</label></th><td><input name="provider_name" required /></td></tr>
            <tr><th><label for="base_api_url">Base URL</label></th><td><input name="base_api_url" required /></td></tr>
            <tr><th><label for="paystack_subaccount">Paystack Subaccount</label></th><td><input name="paystack_subaccount" required /></td></tr>
        </table>
        <input type="submit" name="add_provider" class="button-primary" value="Add Provider" />
    </form>';

    if (!empty($providers)) {
        echo '<h2>Registered Providers</h2>';
        echo '<form method="post">';
        echo '<table class="widefat">';
        echo '<thead><tr><th>ID</th><th>Name</th><th>Base URL</th><th>Paystack Subaccount</th><th>API Key</th><th>HMAC Secret</th><th>Active</th><th>Actions</th></tr></thead><tbody>';

        foreach ($providers as $p) {
            $api_key_id = 'api_key_' . $p->provider_id;
            $hmac_id = 'hmac_secret_' . $p->provider_id;

            echo '<tr>';
            echo '<td>' . esc_html($p->provider_id) . '</td>';
            echo '<td>' . esc_html($p->provider_name) . '</td>';
            echo '<td>' . esc_url($p->base_api_url) . '</td>';

            echo '<td>' . esc_html($p->paystack_subaccount) . '</td>';

            echo '<td><span class="key-container">
                    <span id="' . esc_attr($api_key_id) . '" class="key-text" data-value="' . esc_attr($p->api_key) . '" data-visible="false">••••••••••••••••••••••••••••••</span>
                    <span id="' . esc_attr($api_key_id) . '-toggle" onclick="toggleKeyVisibility(\'' . esc_js($api_key_id) . '\')">' . $eyeSVG . '</span>
                  </span></td>';

            echo '<td><span class="key-container">
                    <span id="' . esc_attr($hmac_id) . '" class="key-text" data-value="' . esc_attr($p->hmac_secret) . '" data-visible="false">••••••••••••••••••••••••••••••</span>
                    <span id="' . esc_attr($hmac_id) . '-toggle" onclick="toggleKeyVisibility(\'' . esc_js($hmac_id) . '\')">' . $eyeSVG . '</span>
                  </span></td>';

            echo '<td>' . ($p->active ? 'Yes' : 'No') . '</td>';

            echo '<td>
                <form method="post" style="display:inline;">
                    <input type="hidden" name="provider_id" value="' . esc_attr($p->provider_id) . '" />
                    <input type="submit" name="api_reroll_key" class="button" value="Reroll API Key" />
                </form>
                <form method="post" style="display:inline; margin-left: 5px;">
                    <input type="hidden" name="provider_id" value="' . esc_attr($p->provider_id) . '" />
                    <input type="submit" name="api_reroll_hmac" class="button" value="Reroll HMAC Secret" />
                </form>
            </td>';            

            echo '</tr>';
        }

        echo '</tbody></table>';
        echo '</form>';
    }

    echo '</div>';

    ?>
    <style>
      .key-container {
        position: relative;
        display: inline-flex;
        align-items: center;
        font-family: monospace;
      }
      .key-text {
        user-select: all;
        margin-right: 6px;
      }
      .toggle-visibility {
        cursor: pointer;
        width: 20px;
        height: 20px;
        fill: #555;
        transition: fill 0.2s ease;
      }
      .toggle-visibility:hover {
        fill: #000;
      }
    </style>

    <script>
      const eyeSVG = `<svg viewBox="0 0 24 24" class="toggle-visibility" xmlns="http://www.w3.org/2000/svg"><path d="M12 5c-7 0-10 7-10 7s3 7 10 7 10-7 10-7-3-7-10-7zm0 12a5 5 0 110-10 5 5 0 010 10z"/><circle cx="12" cy="12" r="2.5"/></svg>`;
      const eyeOffSVG = `<svg viewBox="0 0 24 24" class="toggle-visibility" xmlns="http://www.w3.org/2000/svg"><path d="M17.94 17.94A9.959 9.959 0 0112 19c-7 0-10-7-10-7a18.093 18.093 0 014.49-6.22m1.5-1.5L1 1m21 21l-6.5-6.5M15 12a3 3 0 01-3 3m-3-3a3 3 0 013-3"/></svg>`;

      function toggleKeyVisibility(id) {
        const keyElem = document.getElementById(id);
        const toggleBtn = document.getElementById(id + '-toggle');
        if (keyElem.dataset.visible === 'false') {
          keyElem.textContent = keyElem.dataset.value;
          keyElem.dataset.visible = 'true';
          toggleBtn.innerHTML = eyeOffSVG;
        } else {
          keyElem.textContent = '••••••••••••••••••••••••••••••';
          keyElem.dataset.visible = 'false';
          toggleBtn.innerHTML = eyeSVG;
        }
      }
    </script>
    <?php
}

//==============
//Provider Admin
//==============

add_action('admin_menu', 'provider_services_menu');
function provider_services_menu() {

    if (current_user_can('provider')) {
        add_menu_page(
            'Provider Services Manager',
            'Provider Services',
            'read',
            'provider-services-manager',
            'provider_services_manager_page',
            'dashicons-hammer',
            3
        );
    }
}

function provider_services_manager_page() {
    global $wpdb;

    $providers_table = $wpdb->prefix . 'provider_sites';
    $services_table = $wpdb->prefix . 'services'; // fixed table name
    $current_user = wp_get_current_user();
    $username = $current_user->user_login;

    $provider = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM {$providers_table} WHERE provider_name = %s",
        $username
    ));

    if (!$provider) return;

    $provider_id = $provider->provider_id;

    echo '<div class="wrap"><h1>Provider Services Manager</h1>';

    // Add service
    if (isset($_POST['add_service_submit'])) {
        $service_name = sanitize_text_field($_POST['service_name']);
        $service_cost = floatval($_POST['service_cost']);
        $max_spots = intval($_POST['max_spots']);

        // Check if the provider already has this service by name
        $existing = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM $services_table WHERE service_name = %s AND provider_id = %d",
            $service_name, $provider_id
        ));

        if ($existing > 0) {
            echo '<div class="notice notice-warning"><p>Service already exists.</p></div>';
        } else {
            $wpdb->insert($services_table, [
                'provider_id'   => $provider_id,
                'service_name'  => $service_name,
                'service_cost'  => $service_cost,
                'max_spots'     => $max_spots
            ]);

            echo '<div class="updated"><p>Service added.</p></div>';
        }
    }

    if (isset($_POST['delete_service_id'])) {
        $delete_id = intval($_POST['delete_service_id']);

        $valid = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM $services_table WHERE service_id = %d AND provider_id = %d",
            $delete_id, $provider_id
        ));

        if ($valid) {
            $wpdb->delete($services_table, ['service_id' => $delete_id]);
            echo '<div class="updated"><p>Service deleted.</p></div>';
        }
    }


    if (isset($_POST['edit_service_submit'])) {
        $edit_id = intval($_POST['edit_service_id']);
        $new_cost = floatval($_POST['new_service_cost']);

        $valid = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM $services_table WHERE service_id = %d AND provider_id = %d",
            $edit_id, $provider_id
        ));

        if ($valid) {
            $wpdb->update($services_table, ['service_cost' => $new_cost], ['service_id' => $edit_id]);
            echo '<div class="updated"><p>Service cost updated.</p></div>';
        }
    }

    echo '<form method="post" style="margin-bottom: 1em;">';
    echo '<input type="text" name="service_name" placeholder="New Service Name" required> ';
    echo '<input type="number" name="service_cost" placeholder="Cost" step="0.01" required> ';
    echo '<input type="number" name="max_spots" placeholder="Max Spots" required> ';
    echo '<input type="submit" name="add_service_submit" class="button button-primary" value="Add Service">';
    echo '</form>';

    $services = $wpdb->get_results($wpdb->prepare(
        "SELECT * FROM $services_table WHERE provider_id = %d",
        $provider_id
    ));

    if ($services) {
        echo '<table class="widefat striped">';
        echo '<thead><tr><th>Service Name</th><th>Cost</th><th>Max Spots</th><th>Actions</th></tr></thead><tbody>';

        foreach ($services as $service) {
            echo '<tr>';
            echo '<td>' . esc_html($service->service_name) . '</td>';
            echo '<td>' . esc_html(number_format($service->service_cost, 2)) . '</td>';
            echo '<td>' . esc_html($service->max_spots) . '</td>';
            echo '<td>';
            echo '<form method="post" style="display:inline-block;margin-right:10px;">';
            echo '<input type="hidden" name="delete_service_id" value="' . intval($service->service_id) . '">';
            echo '<input type="submit" class="button button-secondary" value="Delete">';
            echo '</form>';

            echo '<form method="post" style="display:inline-block;">';
            echo '<input type="hidden" name="edit_service_id" value="' . intval($service->service_id) . '">';
            echo '<input type="number" name="new_service_cost" placeholder="New Cost" step="0.01" style="width:100px;"> ';
            echo '<input type="submit" name="edit_service_submit" class="button" value="Update">';
            echo '</form>';
            echo '</td>';
            echo '</tr>';
        }

        echo '</tbody></table>';
    } else {
        echo '<p><em>No services found for this provider.</em></p>';
    }

    echo '</div>';
}

add_action('admin_menu', 'provider_service_time_slots');
function provider_service_time_slots() {
    if (current_user_can('provider')) {
        add_menu_page(
            'Service Availability Editor',
            'Service Availability',
            'read',
            'provider-services-time-manager',
            'provider_service_time_slots_page',
            'dashicons-clock',
            3
        );
    }
}

function provider_service_time_slots_page() {
    global $wpdb;

    $providers_table = $wpdb->prefix . 'provider_sites';
    $services_table = $wpdb->prefix . 'services';
    $availability_table = $wpdb->prefix . 'availability';

    $current_user = wp_get_current_user();
    $username = $current_user->user_login;

    $provider = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM {$providers_table} WHERE provider_name = %s",
        $username
    ));

    if ($provider === null) {
        echo '<div class="notice notice-error"><p>Provider not found.</p></div>';
        return;
    }

    echo '<div class="wrap"><h1>Service Availability Editor</h1>';

    $provider_id = $provider->provider_id;
    $today = new DateTime();

    // Handle undo
    if (isset($_POST['undo_action'], $_POST['undo_slots'])) {
        $undo_data = json_decode(base64_decode(sanitize_text_field($_POST['undo_slots'])), true);
        $deleted_count = 0;
        foreach ($undo_data as $slot) {
            $deleted = $wpdb->delete($availability_table, [
                'service_id' => intval($slot['service_id']),
                'available_date' => sanitize_text_field($slot['available_date']),
                'time_slot' => sanitize_text_field($slot['time_slot']),
                'status' => 'a'
            ]);
            if ($deleted !== false) {
                $deleted_count += $deleted;
            }
        }
        echo '<div class="notice notice-warning"><p>Undo complete. Removed ' . $deleted_count . ' spots.</p></div>';
    }

    $services = $wpdb->get_results($wpdb->prepare(
        "SELECT * FROM {$services_table} WHERE provider_id = %d",
        $provider_id
    ));

    if (empty($services)) {
        echo '<p><em>No services found for this provider.</em></p></div>';
        return;
    }

    foreach ($services as $service) {
        $service_id = $service->service_id;
        $service_name = $service->service_name;
        $max_spots = $service->max_spots;

        echo '<h2 style="border-bottom: 2px solid black; padding-bottom: 5px; margin-bottom: 10px;">' . esc_html($service_name) . '</h2>';

        if (isset($_POST['add_time_slot_' . $service_id])) {
            $time_slot = sanitize_text_field($_POST['time_slot']);
            $time_slot_length = intval($_POST['time_slot_length']);
            $repeating = sanitize_text_field($_POST['repeating_range']);
            $days_selected = [];

            $all_days = ['monday','tuesday','wednesday','thursday','friday','saturday','sunday'];
            foreach ($all_days as $day) {
                if (!empty($_POST[$day])) {
                    $days_selected[] = $day;
                }
            }

            if (empty($days_selected)) {
                echo '<div class="notice notice-error"><p>No days selected.</p></div>';
                continue;
            }

            $inserted_slots = [];
            $inserted_count = 0;
            $duplicates = 0;
            $skipped = 0;

            $dates_to_insert = [];

            $interval_days = [
                'this_week' => 7,
                'next_week' => 7,
                'full_month' => 31,
            ];

            $start_offset = ($repeating === 'next_week') ? 7 : 0;
            $days_range = $interval_days[$repeating] ?? 0;

            for ($i = 0; $i < $days_range; $i++) {
                $date = (clone $today)->modify("+$i days");
                if ($i < $start_offset) continue;

                $weekday = strtolower($date->format('l'));
                if (in_array($weekday, $days_selected)) {
                    $dates_to_insert[] = $date->format('Y-m-d');
                }
            }

            foreach ($dates_to_insert as $date_str) {
                // Check how many already exist for that day/time
                $existing = $wpdb->get_var($wpdb->prepare(
                    "SELECT COUNT(*) FROM {$availability_table}
                     WHERE service_id = %d AND available_date = %s AND time_slot = %s AND status = 'a'",
                    $service_id, $date_str, $time_slot
                ));

                if ($existing >= $max_spots) {
                    $skipped++;
                    continue;
                }

                $spots_to_add = $max_spots - $existing;

                for ($i = 0; $i < $spots_to_add; $i++) {
                    $success = $wpdb->insert($availability_table, [
                        'service_id'           => $service_id,
                        'available_date'       => $date_str,
                        'time_slot'            => $time_slot,
                        'time_slot_length_min' => $time_slot_length,
                        'status'               => 'a',
                    ]);

                    if ($success !== false) {
                        $inserted_count++;
                        $inserted_slots[] = [
                            'service_id' => $service_id,
                            'available_date' => $date_str,
                            'time_slot' => $time_slot
                        ];
                    }
                }
            }

            if ($inserted_count > 0) {
                $undo_data = base64_encode(json_encode($inserted_slots));
                echo '<div class="updated"><p>Added ' . $inserted_count . ' slot(s). ';
                if ($skipped > 0) {
                    echo $skipped . ' duplicate(s) not added (max spots reached). ';
                }
                echo '<form method="post" style="display:inline;">';
                echo '<input type="hidden" name="undo_slots" value="' . esc_attr($undo_data) . '">';
                echo '<input type="submit" name="undo_action" class="button button-secondary" value="Undo">';
                echo '</form>';
                echo '</p></div>';
            } else {
                echo '<div class="notice notice-warning"><p>No new slots added. All selected days may already be full.</p></div>';
            }
        }

        // Form
        echo '<form method="post" style="margin-bottom: 1em;" onsubmit="return confirm(\'Are you sure you want to add this time slot?\')">';
        echo '<div style="font-weight: bold; margin-bottom: 8px;">Time Slot</div>';
        echo '<input type="time" name="time_slot" required> ';
        echo '<input type="number" name="time_slot_length" placeholder="Length (minutes)" min="1" required> ';

        echo '<div style="margin: 10px 0;">';
        echo '<div style="font-weight: bold; margin-bottom: 8px;">Days</div>';
        echo '<div style="display: flex; flex-wrap: wrap; gap: 12px;">';
        foreach (['monday','tuesday','wednesday','thursday','friday','saturday','sunday'] as $day) {
            echo '<label style="display: flex; align-items: center; gap: 5px;">';
            echo '<input type="checkbox" name="' . esc_attr($day) . '"> ' . esc_html(ucfirst($day));
            echo '</label>';
        }
        echo '</div></div>';

        echo '<div style="font-weight: bold; margin-bottom: 8px;">Repeat</div>';
        echo '<select name="repeating_range" required style="min-width: 180px;">';
        echo '<option value="">-- Select Repeating Range --</option>';
        echo '<option value="this_week">This Week</option>';
        echo '<option value="next_week">Next Week</option>';
        echo '<option value="full_month">Full Month</option>';
        echo '</select>';

        echo '<input type="submit" name="add_time_slot_' . esc_attr($service_id) . '" class="button button-primary" value="Add Time Slot">';
        echo '</form>';
    }

    echo '<div style="border-top: 2px solid black; margin: 20px 0; padding-top: 10px;">';
    echo do_shortcode('[provider_admin_custom_calendar]');
    echo '</div></div>';
}




//=============
//API FUNCTIONS
//=============

add_action('rest_api_init', function () {
    register_rest_route('api/v1', '/provider_setup_all_spots_service_availability', [
        'methods' => 'POST',
        'callback' => 'provider_setup_all_spots_service_availability',
        'permission_callback' => '__return_true', //Verification is done within function.
    ]);
});

//For provider to do an initial setup all spots for a service. It is an INSERT, do not use as an UPDATE.
function provider_setup_all_spots_service_availability(WP_REST_Request $request){
    global $wpdb;

    $data = verify_and_decrypt_response($request);

    if ($data instanceof WP_REST_Response) {
        return $data;
    }

    $body_data = $data['body_data'];
    $provider_row = $data['provider_row'];
    $provider_id = $provider_row->provider_id;
    $service_name = $body_data['service_name'] ?? null;
    if(!isset($service_name))
    {
        return new WP_REST_Response(['message' => 'service_name not found (sent incorretly?)'], 404);
    }
    $service_id = $wpdb->get_var(
        $wpdb->prepare("SELECT service_id FROM {$wpdb->prefix}services WHERE provider_id = %d AND service_name = %s", $provider_id, $service_name)
    );
    if(empty($service_id))
    {
        return new WP_REST_Response(['message' => 'No service found in DB '], 500);
    }
    $dates_availability = $body_data['dates_availability'] ?? null;
    if(!isset($dates_availability) || !is_array($dates_availability))
    {
        return new WP_REST_Response(['message' => 'dates_availability not found or not an array (sent incorretly?)'], 404);
    }

    $availability_table = $wpdb->prefix . 'availability';

    foreach ($dates_availability as $entry) {
        $provider_db_id = intval($entry['id'] ?? null);
        $available_date = sanitize_text_field($entry['date'] ?? '');
        $time_slot = sanitize_text_field($entry['time_slot'] ?? '00::00');
        $time_slot_length_min = intval($entry['time_slot_length_min'] ?? 0);
        $status = sanitize_text_field($entry['status'] ?? 'a');

        $insert_query = $wpdb->query(
            $wpdb->prepare("
                INSERT INTO $availability_table (service_id, provider_db_id, available_date, time_slot, time_slot_length_min, status) VALUES (%d, %d, %s, %s, %d, %s)",
                $service_id, $provider_db_id, $available_date, $time_slot, $time_slot_length_min, $status)
        );
    }    

    return new WP_REST_Response(['message' => 'Availability slots inserted successfully'], 201);
}

add_action('rest_api_init', function () {
    register_rest_route('api/v1', '/provider_update_service_spots_availability', [
        'methods' => 'POST',
        'callback' => 'provider_update_service_spots_availability',
        'permission_callback' => '__return_true', //Verification is done within function.
    ]);
});

//For provider to UPDATE a spot that was booked/pending (Does NOT add new spots)
function provider_update_service_spots_availability(WP_REST_Request $request){
    global $wpdb;

    $data = verify_and_decrypt_response($request);

    if ($data instanceof WP_REST_Response) {
        return $data;
    }

    $body_data = $data['body_data'];
    $provider_row = $data['provider_row'];
    $provider_id = $provider_row->provider_id;
    $service_name = $body_data['service_name'] ?? null;
    if(!isset($service_name))
    {
        return new WP_REST_Response(['message' => 'service_name not found (sent incorretly?)'], 404);
    }
    $service_id = $wpdb->get_var(
        $wpdb->prepare("SELECT service_id FROM {$wpdb->prefix}services WHERE provider_id = %d AND service_name = %s", $provider_id, $service_name)
    );
    if(empty($service_id))
    {
        return new WP_REST_Response(['message' => 'No service found in DB '], 500);
    }
    $dates_availability = $body_data['dates_availability'] ?? null;
    if(!isset($dates_availability) || !is_array($dates_availability))
    {
        return new WP_REST_Response(['message' => 'dates_availability not found or not an array (sent incorretly?)'], 404);
    }

    foreach ($dates_availability as $entry) {
        if (!isset($entry['id'])) {
            return new WP_REST_Response(['message' => 'Missing ID'], 400);
        }
        $provider_db_id = intval($entry['id'] ?? null);
        if (!isset($entry['status'])) {
            return new WP_REST_Response(['message' => 'Missing status'], 400);
        }
        $status = sanitize_text_field($entry['status'] ?? null);

        $updated_rows = $wpdb->query(
            $wpdb->prepare("
                UPDATE $availability_table
                SET status = %s, hold_until = NULL
                WHERE provider_db_id = %d AND service_id = %d
            ", $status, $provider_db_id, $service_id)
        );
    }

    return new WP_REST_Response(['message' => 'Availability slots inserted successfully'], 201);
}

//Add to all udpates to db through bookings
function main_update_services_availability($provider_info){   

    $base_api_url = $provider_info['base_api_url'];
    $endpoint = $base_api_url . "/main_update_service_availability";

    $api_key = $provider_info['api_key'];
    $hmac_secret = $provider_info['hmac_secret'];
    $timestamp = time();

    $dates_availability = [];
    foreach ($provider_info['availabilities'] as $avail) {
        $dates_availability[] = [
            'id' => $avail['provider_db_id'],
            'status' => $avail['status'],
        ];
    }

    $payload_array = [
        'dates_availability' => $dates_availability,
    ];

    $payload_json = json_encode($payload_array);

    $signature = hash_hmac('sha256', $payload_json . $timestamp, $hmac_secret);

    $response = wp_remote_post($endpoint, [
        'headers' => [
            'Content-Type' => 'application/json',
            'X-API-KEY' => $api_key,
            'X-Signature' => $signature,
            'X-Timestamp' => $timestamp,
        ],
        'body' => $payload_json,
        'timeout' => 15,
    ]);

    $status_code = wp_remote_retrieve_response_code($response);
    $body = wp_remote_retrieve_body($response);
    $body_json = json_decode($body, true);

    if (is_wp_error($response)) {
    error_log('Request failed: ' . $response->get_error_message());
    return [
        'success' => false,
        'error' => 'Request failed: ' . $response->get_error_message(),
    ];
    }

    if ($status_code < 200 || $status_code >= 300) {
        error_log("API responded with status code $status_code: $body");
        return [
            'success' => false,
            'error' => "API responded with status code $status_code",
            'response_body' => $body_json,
        ];
    }
        
    return [
        'success' => true,
        'data' => $body_json,
    ];
}

function verify_and_decrypt_response($request){
    global $wpdb;

    $api_key = $request->get_header('x-api-key');
    $provider_row = $wpdb->get_row(
        $wpdb->prepare("SELECT * FROM {$wpdb->prefix}provider_sites WHERE api_key = %s AND active = 1", $api_key)
    );

    if (!$provider_row || $api_key !== $provider_row->api_key) {
        return new WP_REST_Response(['message' => 'Invalid API key'], 403);
    }

    $raw_body = file_get_contents('php://input');
    $timestamp = $_SERVER['HTTP_X_TIMESTAMP'] ?? '';
    $signature = $_SERVER['HTTP_X_SIGNATURE'] ?? '';
    $hmac_secret = $provider_row->hmac_secret ?? '';

    if (abs(time() - (int)$timestamp) > 300) {
        return new WP_REST_Response(['message' => 'Request expired'], 403);
    }

    $calculated = hash_hmac('sha256', $raw_body . $timestamp, $hmac_secret);

    if (!hash_equals($calculated, $signature)) {
        return new WP_REST_Response(['message' => 'Invalid HMAC signature'], 403);
    }

    $body_data = json_decode($raw_body, true);
    return [
        'body_data' => $body_data,
        'provider_row' => $provider_row,
    ];
}

function get_providers_with_db_id_and_status_by_session($session_id) {
    global $wpdb;

    $query = $wpdb->prepare("
        SELECT DISTINCT
            p.provider_id,
            p.provider_name,
            p.api_key,
            p.hmac_secret,
            p.base_api_url,
            a.provider_db_id,
            a.status
        FROM {$wpdb->prefix}provider_sites p
        JOIN {$wpdb->prefix}services s ON p.provider_id = s.provider_id
        JOIN {$wpdb->prefix}availability a ON s.service_id = a.service_id
        WHERE a.session_id = %s
        ORDER BY p.provider_name, a.provider_db_id
    ", $session_id);

    $rows = $wpdb->get_results($query);

    $result = [];

    foreach ($rows as $row) {
        if (!isset($result[$row->provider_id])) {
            $result[$row->provider_id] = [
                'provider_name' => $row->provider_name,
                'api_key' => $row->api_key,
                'hmac_secret' => $row->hmac_secret,
                'base_api_url' => $row->base_api_url,
                'availabilities' => [],
            ];
        }
        $result[$row->provider_id]['availabilities'][] = [
            'provider_db_id' => $row->provider_db_id,
            'status' => $row->status,
        ];
    }

    return $result;
}

function update_providers_availability_spots($session_id) {
    $providers = get_providers_with_db_id_and_status_by_session($session_id);

    foreach ($providers as $provider_id => $provider_info) {
        main_update_services_availability($provider_info);
    }
}

//=========
//FUNCTIONS
//=========

function create_api_key() {
    return bin2hex(random_bytes(16));
}

function create_hmac_secret() {
    return bin2hex(random_bytes(32));
}


//=========
//CRON
//=========
/*
function pcp_release_expired_pending() {
    global $wpdb;
    $availability_table = $wpdb->prefix . 'availability';

    $wpdb->query("
        UPDATE $availability_table
        SET status = 'a', hold_until = NULL
        WHERE status = 'p' AND hold_until < NOW()
    ");
}

add_filter('cron_schedules', function($schedules) {
    $schedules['every_five_minutes'] = [
        'interval' => 300,
        'display' => __('Every 5 Minutes'),
    ];
    return $schedules;
});

add_action('pcp_release_pending_cron', 'pcp_release_expired_pending');

register_activation_hook(__FILE__, function() {
    if (!wp_next_scheduled('pcp_release_pending_cron')) {
        wp_schedule_event(time(), 'every_five_minutes', 'pcp_release_pending_cron');
    }
});

register_deactivation_hook(__FILE__, function() {
    wp_clear_scheduled_hook('pcp_release_pending_cron');
});
*/





