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

    foreach ($posts as $post) {
        if (has_shortcode($post->post_content, 'custom_calendar')) {
            add_filter('pcp_should_enqueue_assets', '__return_true');
            break;
        }
    }

    return $posts;
}

add_action('wp_enqueue_scripts', 'enqueue_custom_calendar_assets');
function enqueue_custom_calendar_assets() {
    if (!apply_filters('pcp_should_enqueue_assets', false)) return;

    wp_enqueue_style('pcp-style', plugin_dir_url(__FILE__) . 'calendar_style.css', [], filemtime(plugin_dir_path(__FILE__) . 'calendar_style.css'));

    wp_enqueue_script('pcp-calendar', plugin_dir_url(__FILE__) . 'calendar.js', ['jquery'], filemtime(plugin_dir_path(__FILE__) . 'calendar.js'), true);

    wp_enqueue_script('paystack', 'https://js.paystack.co/v1/inline.js', [], null, true);

    wp_localize_script('pcp-calendar', 'rest_object', [
        'rest_url' => esc_url_raw(rest_url('pcp/v1/')),
        'nonce'    => wp_create_nonce('wp_rest')
    ]);
}


add_shortcode('custom_calendar', 'pcp_custom_calendar_shortcode');

function pcp_custom_calendar_shortcode() {
    global $wpdb;

    $providers = $wpdb->get_results("SELECT * FROM {$wpdb->prefix}provider_sites");     

    ob_start(); ?>
    <div class="calendar-scroll-wrapper">
    <div id="calendar-container">
        <div id="timer-display">10:00</div>
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
            <label for="client-name">Name:</label><br>
            <input type="text" id="client-name" style="width:100%; padding:8px; margin-bottom:10px;"><br>
            <label for="client-name">Phone Number:</label><br>
            <input type="text" id="client-number" style="width:100%; padding:8px;"><br><br>
            <label for="client-name">Email:</label><br>
            <input type="email" id="client-email" style="width:100%; padding:8px;"><br><br>
            <button id="submit-client-info" style="padding:8px 12px;">Continue</button>
            <button id="cancel-client-info" style="padding:8px 12px; background:#ccc; margin-left:10px;">Cancel</button>
        </div>
    </div>
    </div>

    <?php
    return ob_get_clean();
}

add_action('rest_api_init', function () {
    register_rest_route('pcp/v1', '/services', [
        'methods' => 'GET',
        'callback' => 'pcp_rest_get_services',
        'permission_callback' => '__return_true', // public endpoint, no auth needed (adjust if necessary)
    ]);
});

function pcp_rest_get_services(WP_REST_Request $request) {
    global $wpdb;

    $provider_id = intval($request->get_param('provider'));
    if (!$provider_id) {
        return rest_ensure_response([]);
    }

    $services_table = $wpdb->prefix . 'services';

    $services = $wpdb->get_results($wpdb->prepare(
        "SELECT service_id, service_name, service_cost_main FROM $services_table WHERE provider_id = %d",
        $provider_id
    ));

    return rest_ensure_response($services);
}

add_action('rest_api_init', function () {
    register_rest_route('pcp/v1', '/availability', [
        'methods' => 'GET',
        'callback' => 'pcp_rest_get_availability',
        'permission_callback' => function ($request) {
            $nonce = $request->get_header('X-WP-Nonce');
            return wp_verify_nonce($nonce, 'wp_rest');
        },
    ]);
});


function pcp_rest_get_availability(WP_REST_Request $request) {
    global $wpdb;

    $availability_table = $wpdb->prefix . 'availability';

    /*
    // Clear expired holds
    $wpdb->query("
        UPDATE $availability_table
        SET status = 'a', hold_until = NULL, session_id = NULL
        WHERE status = 'p' AND hold_until < NOW()
    ");
    */

    $service_id = intval($request->get_param('service_id'));
    if (!$service_id) {
        return rest_ensure_response([]);
    }

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
        $date = substr($row->available_date, 0, 10);

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

    return rest_ensure_response($availability);
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

    // Prepare REST URL and nonce for JS
    $rest_url = esc_url_raw(rest_url('pcp/v1/'));
    $rest_nonce = wp_create_nonce('wp_rest');

    ob_start();
    ?>
    <div class="calendar-scroll-wrapper">
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
        <div id="client-info-modal" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background-color:rgba(0,0,0,0.6); z-index:9999; align-items:center; justify-content:center;">
            <div style="background:#fff; padding:20px; border-radius:8px; max-width:300px; width:90%; box-shadow:0 4px 10px rgba(0,0,0,0.2);">
                <h3 style="margin-top:0;">Enter Details to continue</h3>
                <label>Name:</label><br>
                <input type="text" id="client-name" style="width:100%; padding:8px; margin-bottom:10px;"><br>
                <label>Phone Number:</label><br>
                <input type="text" id="client-number" style="width:100%; padding:8px;"><br><br>
                <label>Email:</label><br>
                <input type="email" id="client-email" style="width:100%; padding:8px;"><br><br>
                <button id="submit-client-info" style="padding:8px 12px;">Continue</button>
                <button id="cancel-client-info" style="padding:8px 12px; background:#ccc; margin-left:10px;">Cancel</button>
            </div>
        </div>
    </div>

    <script>

        const rest_object = {
            rest_url: "<?= $rest_url ?>",
            nonce: "<?= $rest_nonce ?>"
        };
    </script>

    <script>
    document.addEventListener('DOMContentLoaded', function () {
        const serviceSelect = document.getElementById('service-select');
        const calendarTitle = document.getElementById('calendar-title');
        const prevBtn = document.getElementById('prev-month');
        const nextBtn = document.getElementById('next-month');
        const clientModal = document.getElementById("client-info-modal");
        const continueBtn = document.getElementById("submit-client-info");
        const cancelBtn = document.getElementById("cancel-client-info");

        const shortWeekdays = ["Mon", "Tue", "Wed", "Thu", "Fri", "Sat", "Sun"];
        const fullWeekdays = ["Monday", "Tuesday", "Wednesday", "Thursday", "Friday", "Saturday", "Sunday"];

        function updateWeekdays() {
            const headerDivs = document.querySelectorAll(".calendar-header > div");
            if (window.innerWidth <= 600) {
            headerDivs.forEach((div, i) => div.textContent = shortWeekdays[i]);
            } else {
            headerDivs.forEach((div, i) => div.textContent = fullWeekdays[i]);
            }
        }

        // Initial run
        updateWeekdays();

        // Update on window resize
        window.addEventListener("resize", updateWeekdays);
        let currentSelectedId;

        let currentYear = new Date().getFullYear();
        let currentMonth = new Date().getMonth();
        const today = new Date();

        continueBtn.addEventListener("click", function () {
            const name = document.getElementById("client-name").value.trim();
            const email = document.getElementById("client-email").value.trim();
            const number = document.getElementById("client-number").value.trim();

            if (!name || !email || !number) {
                alert("Please fill in all fields (If missing type anything).");
                return;
            }


            fetch(rest_object.rest_url + 'admin/book_spot_available', {
                                    method: 'POST',
                                    headers: {
                                        'Content-Type': 'application/json',
                                        'X-WP-Nonce': rest_object.nonce,
                                    },
                                    body: JSON.stringify({
                                        availability_id: currentSelectedId,
                                        customer_name: name,
                                        customer_email: email,
                                        customer_number: number,
                                    }),
                                })
                                .then(res => res.json())
                                .then(data => {
                                    if (data.success) {
                                        updateCalendar();
                                        clientModal.style.display = 'none';                                        
                                        alert(data.message);                                        
                                        /*
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
                                        */
                                    } else {
                                        alert("Error: " + (data.message || 'Unknown error'));
                                    }
                                })
                                .catch(err => {
                                    alert('An unexpected error occurred.');
                                });

                                
        });

        cancelBtn.addEventListener("click", function () {
            clientModal.style.display = "none"; // close modal
        });

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

            fetch(`${rest_object.rest_url}availability?service_id=${encodeURIComponent(selectedService)}`, {
                method: 'GET',
                headers: {
                    'X-WP-Nonce': rest_object.nonce
                }
            })
            .then(res => {
                if (!res.ok) {
                    throw new Error(`HTTP error! status: ${res.status}`);
                }
                return res.json();
            })
            .then(data => {
                generateCalendar(currentYear, currentMonth, data);
                updateControls();
            })
            .catch(err => {
                console.error("Availability fetch failed", err);
                alert('Failed to load availability data. See console for details.');
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
                        btn.textContent = "Book";

                        // Styling for button (same as your styles)
                        Object.assign(btn.style, {
                            position: "absolute",
                            top: "0",
                            left: "0",
                            width: "100%",
                            height: "100%",
                            backgroundColor: "#4caf50",
                            color: "white",
                            border: "none",
                            borderRadius: "4px",
                            fontSize: "14px",
                            display: "flex",
                            justifyContent: "center",
                            alignItems: "center",
                            opacity: "0",
                            pointerEvents: "none",
                            transition: "opacity 0.2s ease-in-out",
                            zIndex: "10",
                            cursor: "pointer"
                        });

                        btn.addEventListener("click", (e) => {
                            currentSelectedId = -1;
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
                                
                                //ADD UPDATE
                                currentSelectedId = selectedAvailabilityId;

                                clientModal.style.display = "flex";
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
  grid-template-columns: repeat(7, minmax(0, 1fr));
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
  grid-template-columns: repeat(7, minmax(0, 1fr));
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
  overflow: hidden;
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

.book-btn {
  border: none;
  border-radius: 4px;
  font-size: 11px;
  user-select: none;
  cursor: pointer;
}

.service-heading {
  font-size: 23px;
  margin-top: 10px;
  margin-bottom: 7px;
  text-decoration: underline;
}

#booking_summary table.booking-table {
  table-layout: fixed;
  width: 100%;
  border-collapse: collapse;
}

#booking_summary table.booking-table td {
  /*padding: 6px 12px;*/
  font-size: 1rem;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}

#booking_summary table.booking-table td:nth-child(1) {
  width: 30%; /* Date */
}

#booking_summary table.booking-table td:nth-child(2) {
  width: 30%; /* Time */
}

#booking_summary table.booking-table td:nth-child(3) {
  width: auto; /* Cost */
  text-align: right;
}

#booking_summary table.booking-table td:nth-child(4) {
  width: auto; /* Delete button */
  text-align: right;
  min-width: 60px;
}

#booking_summary h2 {
  font-size: 30px;
  font-weight: bold;
  border-bottom: 2px solid black;
  padding-bottom: 5px;              
  margin-bottom: 12px;
}

#booking_summary h3 {
  font-size: 25px;
  font-weight: bold;
  margin-bottom: 10px;
}

#booking_summary h4 {
  margin-top: 10px;
}


.booking-table {
  width: 100%;
  border-collapse: collapse;
  margin-bottom: 20px;
}

.booking-table th, .booking-table td {
  text-align: left;
  padding-top: 0.5%;
}

.booking-total-table {
  width: 100%;
  border-top: 2px solid black;
  border-bottom: 2px solid black;
  margin-top: 15px;
  padding-top: 10px;
  font-weight: bold;
  font-size: 1.2rem;
  border-collapse: collapse;
}

.total-label {
  text-align: left;
  padding: 10px 0;
}

.total-amount {
  text-align: right;
  padding: 10px 0;
}

.booked-wave {
  animation: wave-text 1s ease-in-out forwards;
  pointer-events: none; /* disables clicks */
  cursor: default;
  color: black !important; /* dark yellow */
  background-color: yellow !important;
}

.checkout-btn {
  padding: 10px 20px;
  font-size: 16px;
  background-color: #1e88e5;
  color: white;
  border: none;
  border-radius: 8px;
  cursor: pointer;
  transition: background-color 0.3s ease;
}

.checkout-btn:hover {
  background-color: #1565c0;
}

@keyframes wave-text {
  0%, 100% {
    text-shadow: none;
  }
  50% {
    text-shadow:
      0 0 5px #b59f00,
      0 0 10px #b59f00,
      0 0 20px #b59f00;
  }
}

/* Darker Book Now text */
.book-btn {
  color: #0b3d0b; /* dark green, for example */
  font-weight: 600;
}

.book-btn:disabled {
  background-color: #0b3d0b;
  cursor: not-allowed;
}

@media (max-width: 768px) {
  #calendar-container {
    max-width: 100%;
    padding: 0 10px;
  }

  .calendar-header,
  .calendar-body {
    grid-template-columns: repeat(7, 1fr);
  }

  .day-cell {
    min-height: 110px;
    font-size: 11px;
  }

  select {
    min-width: 140px;
  }
}

/* --- MOBILE FIXES --- */
@media (max-width: 900px) {
  /* Make day cells taller so slots don't squeeze */
  .day-cell {
    min-height: auto;
    padding: 2px;
    font-size: 12px;
  }

  /* Stack slots vertically like cards instead of tables */
  .slots-wrapper {
    margin-top: 2px;
    border-width: 1px;
  }

  .slots-table {
    display: block;
    width: 100%;
    font-size: 13px;
    border: 1px solid #ddd;
    border-radius: 6px;
    margin-bottom: 6px;
    overflow: hidden;
  }

  .slots-table tr {
    display: flex;
    flex-direction: column;
    align-items: flex-start;
    padding: 6px;
  }

  .slots-table td {
    display: block;
    width: 100%;
    padding: 2px 3px;
    text-align: left;
    border: none !important;
  }

  .slots-table td.time-cell {
    padding: 0px 1px;
    font-weight: bold;
    background: none;
    white-space: normal;
    font-size: 12px;
  }

  .slots-table td.spots {
    background: none;
    padding: 0px 1px;
    font-size: 12px;
  }

  /* Make Book button always visible (no hover dependency) */
  .book-btn {
  position: relative !important;
  opacity: 1 !important;
  pointer-events: auto !important;
  display: inline-block;          
  margin-top: 2px;
  padding: 0px 2px;              
  font-size: 12px !important;     
  white-space: normal;            
  max-width: 100%;                
  overflow: hidden;
  box-sizing: border-box;
}

label,
select {
  display: block;
}

}


.calendar-scroll-wrapper {
    overflow-x: auto;       
    -webkit-overflow-scrolling: touch; 
}

#calendar-container {
    min-width: 500px;      
}


    </style>

    <?php
    return ob_get_clean();
}


add_action('rest_api_init', function () {
    register_rest_route('pcp/v1', '/admin/book_spot_available', [
        'methods' => 'POST',
        'callback' => 'admin_book_spot_available_rest',
        'permission_callback' => function ($request) {
    $nonce = $request->get_header('X-WP-Nonce');
    if (!wp_verify_nonce($nonce, 'wp_rest')) {
        return false;
    }

    $user = wp_get_current_user();
    if (!$user->exists()) {
        return false;
    }

    global $wpdb;
    $availability_id = intval($request->get_param('availability_id'));
    if (!$availability_id) {
        return false;
    }

    // Find provider linked to logged-in user
    $provider = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM {$wpdb->prefix}provider_sites WHERE provider_name = %s",
        $user->user_login
    ));
    if (!$provider) {
        return false;
    }

    // Check if the availability slot belongs to this provider via the service
    $service = $wpdb->get_row($wpdb->prepare(
        "SELECT s.provider_id 
         FROM {$wpdb->prefix}availability AS a
         INNER JOIN {$wpdb->prefix}services AS s ON a.service_id = s.service_id
         WHERE a.availability_id = %d",
         $availability_id
    ));
    if (!$service) {
        return false;
    }

    return $service->provider_id == $provider->provider_id;
    },
        ]);
});

function admin_book_spot_available_rest(WP_REST_Request $request) {
    global $wpdb;

    $availability_id = intval($request->get_param('availability_id'));
    $customer_name = sanitize_text_field($request->get_param('customer_name'));
    $customer_email = sanitize_text_field($request->get_param('customer_email'));
    $customer_number = sanitize_text_field($request->get_param('customer_number'));

    if (!$availability_id || $availability_id == -1) {
        return new WP_REST_Response([
            'success' => false,
            'message' => 'Missing spot ID',
        ], 400);
    }
    if (!$customer_name) {
        return new WP_REST_Response([
            'success' => false,
            'message' => 'Missing customer name',
        ], 400);
    }
    if (!$customer_email) {
        return new WP_REST_Response([
            'success' => false,
            'message' => 'Missing customer email',
        ], 400);
    }

    if (!$customer_number) {
        return new WP_REST_Response([
            'success' => false,
            'message' => 'Missing customer number',
        ], 400);
    }

    $availability_table = $wpdb->prefix . 'availability';

    $updated = $wpdb->query(
        $wpdb->prepare("UPDATE $availability_table
        SET status = 'b'
        WHERE availability_id = %d AND status = 'a'", $availability_id)
    );

    if ($updated === false) {
        return new WP_REST_Response([
            'success' => false,
            'message' => 'Database error',
        ], 500);
    }

    if ($updated === 0) {
        return new WP_REST_Response([
            'success' => false,
            'message' => 'Spot not available or already booked',
        ], 409);
    }

    $bookings_table = $wpdb->prefix . 'bookings';

    $booking_update = null;

    if ($updated) {
        $booking_update = $wpdb->insert(
            $bookings_table,
            [
                'availability_id' => $availability_id,
                'booked_by_main'  => 0,
                'customer_name'   => $customer_name,
                'customer_email'  => $customer_email,
                'customer_number' => $customer_number,
                'created_at'      => current_time('mysql')
            ]
        );

        if($booking_update === false){
            $wpdb->query(
                $wpdb->prepare("UPDATE $availability_table
                SET status = 'a'
                WHERE availability_id = %d AND status = 'b'", $availability_id)
            );

            return new WP_REST_Response([
                'success' => false,
                'message' => 'Database error in bookings table, rolled back',
            ], 500);
        }
    }    

    return rest_ensure_response([
        'success' => true,
        'message' => 'Spot booked',
    ]);
}

//========
//Rest API 
//========

add_action('rest_api_init', function () {
    register_rest_route('pcp/v1', '/book_spot', [
        'methods'  => 'POST',
        'callback' => 'pcp_book_spot_in_avail_rest',
        'permission_callback' => function ($request) {
            $nonce = $request->get_header('X-WP-Nonce');
            return wp_verify_nonce($nonce, 'wp_rest');
        },
    ]);

    register_rest_route('pcp/v1', '/release_spot', [
        'methods'  => 'POST',
        'callback' => 'pcp_release_spot_rest',
        'permission_callback' => function ($request) {
            $nonce = $request->get_header('X-WP-Nonce');
            return wp_verify_nonce($nonce, 'wp_rest');
        },
    ]);

    register_rest_route('pcp/v1', '/release_all_spots', [
        'methods'  => ['GET', 'POST'],
        'callback' => 'pcp_release_all_spots_rest',
        'permission_callback' => function ($request) {
            $nonce = $request->get_param('_wpnonce');
            return wp_verify_nonce($nonce, 'wp_rest');
        }
    ]);
});


//Book spot is not a proper booking just desegnating id to availability db
function pcp_book_spot_in_avail_rest($request) {
    global $wpdb;

    $availability_id_list = $request['availability_id_list'];
    $session_id = sanitize_text_field($request['session_id'] ?? '');

    if (!$session_id || !is_array($availability_id_list) || empty($availability_id_list)) {
        return new WP_REST_Response(['success' => false, 'message' => 'Missing session or spot IDs'], 400);
    }

    $availability_table = $wpdb->prefix . 'availability';
    $selected_availability_id = null;

    foreach ($availability_id_list as $availability_id) {
        $updated = $wpdb->query(
            $wpdb->prepare("
                UPDATE $availability_table
                SET status = 'p',
                    hold_until = DATE_ADD(NOW(), INTERVAL 15 MINUTE),
                    session_id = %s
                WHERE availability_id = %d AND status = 'a'
            ", $session_id, $availability_id)
        );

        if ($updated) {
            $selected_availability_id = $availability_id;
            break;
        }
    }

    if ($selected_availability_id) {
        return new WP_REST_Response([
            'success' => true,
            'message' => 'Spot reserved',
            'availability_id' => $selected_availability_id
        ], 200);
    } else {
        return new WP_REST_Response([
            'success' => false,
            'message' => 'Sorry, spot is already reserved by someone else!'
        ], 409);
    }

    update_providers_availability_spots($session_id);

    return new WP_REST_Response(['success' => true, 'message' => 'Spot reserved as pending'], 200);
}


function pcp_release_spot_rest($request) {
    global $wpdb;

    $availability_id = intval($request['availability_id']);
    $session_id = sanitize_text_field($request['session_id'] ?? '');

    if (!$availability_id) {
        return new WP_REST_Response(['success' => false, 'message' => 'Missing spot ID'], 400);
    }

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
        return new WP_REST_Response(['success' => false, 'message' => 'Database error'], 500);
    }

    if ($updated === 0) {
        return new WP_REST_Response(['success' => false, 'message' => 'Spot not pending or already released/booked'], 409);
    }

    update_providers_availability_spots($session_id);

    return new WP_REST_Response(['success' => true, 'message' => 'Spot released'], 200);
}

function pcp_release_all_spots_rest($request) {
    global $wpdb;

    $session_id = sanitize_text_field($request->get_param('session_id'));

    if (!$session_id) {
        return new WP_REST_Response(['success' => false, 'message' => 'Missing session ID'], 400);
    }

    $availability_table = $wpdb->prefix . 'availability';

    $updated = $wpdb->query(
        $wpdb->prepare("
            UPDATE $availability_table
            SET status = 'a',
                hold_until = NULL,
                session_id = NULL
            WHERE session_id = %s AND status = 'p'
        ", $session_id)
    );

    update_providers_availability_spots($session_id);

    return new WP_REST_Response(['success' => true, 'message' => 'All spots released'], 200);
}

add_action('rest_api_init', function () {
    register_rest_route('pcp/v1', '/verify_payment', [
        'methods'  => 'POST',
        'callback' => 'pcp_verify_payment',
        'permission_callback' => function ($request) {
            $nonce = $request->get_header('X-WP-Nonce');
            return wp_verify_nonce($nonce, 'wp_rest');
        },
    ]);
});

function pcp_verify_payment($request){
    global $wpdb;

    $reference = sanitize_text_field($request->get_param('reference'));

    if (empty($reference)) {
        return new WP_REST_Response(['success' => false, 'error' => 'Missing reference'], 400);
    }

    $table = $wpdb->prefix . 'availability';

    $row = $wpdb->get_row($wpdb->prepare(
        "SELECT availability_id, status FROM $table WHERE session_id = %s",
        $reference
    ));

    if (!$row) {
        return new WP_REST_Response(['success' => false, 'error' => 'Session ID not found'], 404);
    }

    if ($row->status === 'b') {
        return new WP_REST_Response(['success' => true, 'message' => 'Already verified (status b)']);
    }

    $paystack_secret = $wpdb->get_var("
        SELECT paystack_api_key_secret
        FROM {$wpdb->prefix}paystack_info
        WHERE id = 1
    ");

    $curl = curl_init();
  
    curl_setopt_array($curl, array(
        CURLOPT_URL => "https://api.paystack.co/transaction/verify/$reference",
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_ENCODING => "",
        CURLOPT_MAXREDIRS => 10,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
        CURLOPT_CUSTOMREQUEST => "GET",
        CURLOPT_HTTPHEADER => array(
            "Authorization: Bearer $paystack_secret",
            "Cache-Control: no-cache",
        ),
    ));
    
    $response = curl_exec($curl);
    $err = curl_error($curl);
    curl_close($curl);

    if ($err) {
        return new WP_REST_Response(['success' => false, 'error' => 'Paystack Error: ' . $err], 500);
    }

    $result = json_decode($response, true);

    $paystack_data = $result['data'];
    $customer_email = sanitize_email($paystack_data['customer']['email']);
    if ($paystack_data['status'] === 'success') {
        $wpdb->update($table, ['status' => 'b'], ['availability_id' => $row->availability_id]);
        create_and_send_email($customer_email, $reference);
        return new WP_REST_Response(['success' => true, 'message' => 'Payment verified successfully']);
    } else{
        $wpdb->update($table, ['status' => 'a'], ['availability_id' => $row->availability_id]);
        return new WP_REST_Response(['success' => false, 'message' => 'Payment verification failed'], 400);
    }
}

add_action('rest_api_init', function () {
    register_rest_route('pcp/v1', '/extend_hold', [
        'methods'  => 'POST',
        'callback' => 'pcp_extend_hold',
        'permission_callback' => function ($request) {
            $nonce = $request->get_header('X-WP-Nonce');
            return wp_verify_nonce($nonce, 'wp_rest');
        },
    ]);
});

function pcp_extend_hold($request) {
    global $wpdb;

   $session_id = sanitize_text_field($request->get_param('sessionId'));

    $availability_table = $wpdb->prefix . 'availability';

    $updated = $wpdb->query(
        $wpdb->prepare("
            UPDATE $availability_table
            SET hold_until = DATE_ADD(NOW(), INTERVAL 10 MINUTE)
            WHERE session_id = %d AND status = 'p'
        ", $session_id)
    );

    if ($updated === false) {
        return new WP_REST_Response(['success' => false, 'message' => 'Database error'], 500);
    }

    return new WP_REST_Response(['success' => true, 'message' => 'Hold unitl updated'], 200);
}




add_action('rest_api_init', function () {
    register_rest_route('pcp/v1', '/init_payment', [
        'methods'             => 'POST',
        'callback'            => 'pcp_rest_init_payment',
        'permission_callback' => function ($request) {
            $nonce = $request->get_header('X-WP-Nonce');
            return wp_verify_nonce($nonce, 'wp_rest');
        }
    ]);
});

function pcp_rest_init_payment($request) {
    global $wpdb;

    $params = $request->get_json_params();

    $session_id = sanitize_text_field($params['session_id'] ?? '');
    $customer_name = sanitize_text_field($params['customer_name'] ?? '');
    $customer_email = sanitize_text_field($params['customer_email'] ?? '');
    $customer_number = sanitize_text_field($params['customer_number'] ?? '');

    if (empty($session_id)) {
        return new WP_REST_Response(['error' => 'Missing session ID'], 400);
    }
    if (empty($customer_name)) {
        return new WP_REST_Response(['error' => 'Missing customer_name'], 400);
    }
    if (empty($customer_email)) {
        return new WP_REST_Response(['error' => 'Missing customer_email'], 400);
    }

    $paystack_secret = $wpdb->get_var("
        SELECT paystack_api_key_secret
        FROM {$wpdb->prefix}paystack_info
        WHERE id = 1
    ");

    if (empty($paystack_secret)) {
        return new WP_REST_Response(['error' => 'No paystack secret key'], 500);
    }

    $results = $wpdb->get_results($wpdb->prepare("
        SELECT 
            a.availability_id,
            s.service_cost_main,
            s.service_cost_provider,
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

    if (empty($results)) {
        return new WP_REST_Response(['error' => 'No availability entries found'], 404);
    }

    $provider_data = [];
    $total_cost = 0;

    foreach ($results as $row) {
        $paystack_subaccount = $row['paystack_subaccount'];
        $cost_provider = floatval($row['service_cost_provider']);
        $cost_main = floatval($row['service_cost_main']);
        $availability_id = intval($row['availability_id']);

        if (!isset($provider_data[$paystack_subaccount])) {
            $provider_data[$paystack_subaccount] = [
                'paystack_subaccount' => $paystack_subaccount,
                'provider_total' => 0,
                'all_availability_ids' => []
            ];
        }

        $provider_data[$paystack_subaccount]['provider_total'] += $cost_provider;
        $provider_data[$paystack_subaccount]['all_availability_ids'][] = $availability_id;
        $total_cost += $cost_main;
    }

    $split = build_split($provider_data, $total_cost);

    $split_code = get_split_code($split, $paystack_secret);    

    if(!$split_code){
        return new WP_REST_Response([
            'error' => 'Split code creation failed'
        ], 500);
    }

    $fields = [
        'email' => $customer_email,
        'amount' => $total_cost * 100,
        'reference' => $session_id,
        'split_code' => $split_code 
    ];

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, "https://api.paystack.co/transaction/initialize");
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($fields));
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        "Authorization: Bearer $paystack_secret",
        "Cache-Control: no-cache"
    ]);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

    $response = curl_exec($ch);
    curl_close($ch);

    $response_data = json_decode($response, true);

    if (!isset($response_data['status']) || !$response_data['status']) {
        return new WP_REST_Response([
            'error' => 'Payment initialization failed',
            'response' => $response_data
        ], 500);
    }
    //Set hold unitl to null as uesr can take as long as they like to do payment, webhook or cron will verify
    $wpdb->query($wpdb->prepare("
        UPDATE {$wpdb->prefix}availability
        SET hold_until = DATE_ADD(NOW(), INTERVAL 5 MINUTE)
        WHERE session_id = %s
    ", $session_id));


    foreach($results as $row) {
        $already_booked = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}bookings WHERE availability_id = %d",
            $availability_id
        ));

        if (!$already_booked) {
            $wpdb->insert(
                "{$wpdb->prefix}bookings",
                [
                    'availability_id' => $availability_id,
                    'customer_name'   => $customer_name,
                    'customer_email'  => $customer_email,
                    'customer_number' => $customer_number,
                    'booked_by_main'  => 1
                ],
                ['%d', '%s', '%s', '%s', '%d']
            );
        }

    }

    return new WP_REST_Response([
        'success' => true,
        'data' => [
            'redirect_url' => $response_data['data']['authorization_url']
        ]
        ], 200
    );
}


function build_split($provider_data, $total_cost){
    $subaccounts = [];
    $operational_cost = intval($total_cost * 100 * 0.01); //1%, *100 for kobo

    //Amounts must be in kobo so *100

    foreach ($provider_data as $data) {
        $share = intval($data['provider_total'] * 100);

        $subaccounts[] = [
            'subaccount' => $data['paystack_subaccount'],
            'share' => $share
        ];
    }    

    $subaccounts[] = [
        'subaccount' => 'ACCT_2try0nqlasfaj7i', //MY_PACKSTACK CODE HARDCODE REPLACE ADD
        'share' => $operational_cost //MY SHARE 
    ];

    return $subaccounts;
}

function get_split_code($split, $paystack_secret){

    $url = "https://api.paystack.co/split";

    $fields = [
        'name' => "Dynamic split" . time(), 
        'type' => "flat",
        'currency' => "ZAR", 
        'bearer_type' => 'account',
        'subaccounts' => $split
    ];

    $fields_string = json_encode($fields);

    $ch = curl_init();
    
    curl_setopt($ch,CURLOPT_URL, $url);
    curl_setopt($ch,CURLOPT_POST, true);
    curl_setopt($ch,CURLOPT_POSTFIELDS, $fields_string);
    curl_setopt($ch, CURLOPT_HTTPHEADER, array(
        "Authorization: Bearer $paystack_secret",
        "Cache-Control: no-cache",
        "Content-Type: application/json"
    ));
    
    curl_setopt($ch,CURLOPT_RETURNTRANSFER, true); 
    
    $result = curl_exec($ch);  

    $response_data = json_decode($result, true);

    if (!isset($response_data['status']) || $response_data['status'] !== true) {
        error_log(print_r($response_data, true));
        return null;
    }
    
    return $response_data['data']['split_code'];

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


function paystack_webhook(WP_REST_Request $request) {
    global $wpdb;
    $availability_table = $wpdb->prefix . 'availability';
    $bookings_table = $wpdb->prefix . 'bookings';

    // Immediately respond 200 OK to Paystack
    $response = new WP_REST_Response(['status' => 'ok'], 200);

    // Send response early
    if (function_exists('fastcgi_finish_request')) {
        fastcgi_finish_request();
    } else {
        ignore_user_abort(true);
        ob_flush();
        flush();
    }

    // Get raw body and signature header from request object
    $input = $request->get_body();
    $signature = $request->get_header('x-paystack-signature');

    // Get your Paystack secret key from DB
    $paystack_secret = $wpdb->get_var("
        SELECT paystack_api_key_secret
        FROM {$wpdb->prefix}paystack_info
        WHERE id = 1
    ");

    if (!$signature || $signature !== hash_hmac('sha512', $input, $paystack_secret)) {
        error_log(date('[Y-m-d H:i:s] ') . "Paystack webhook: Invalid signature");
        return $response;
    }

    $event = json_decode($input);
    if (!$event || !isset($event->data->reference) || !isset($event->event)) {
        error_log(date('[Y-m-d H:i:s] ') . "Paystack webhook: Missing reference or event type");
        return $response;
    }

    $reference = sanitize_text_field($event->data->reference);
    $customer_email = sanitize_text_field($event->data->customer->email);
    $event_type = sanitize_text_field($event->event);

    if ($event_type === 'charge.success' || $event_type === 'transfer.success') {
        $updated = $wpdb->query(
            $wpdb->prepare("
                UPDATE $availability_table
                SET status = 'b', hold_until = NULL
                WHERE status = 'p' AND session_id = %s
            ", $reference)
        );

        create_and_send_email($customer_email, $reference);

        update_providers_availability_spots($reference);

        error_log(date('[Y-m-d H:i:s] ') . "Paystack payment succeeded for session: $reference, availability rows updated: $updated");

    } elseif ($event_type === 'charge.failed' || $event_type === 'transfer.failed') {
        $booking_count = $wpdb->get_var(
            $wpdb->prepare("
                SELECT COUNT(*) FROM $bookings_table b
                JOIN $availability_table a ON b.availability_id = a.availability_id
                WHERE a.session_id = %s
            ", $reference)
        );
        error_log(date('[Y-m-d H:i:s] ') . "Bookings to delete for session $reference: $booking_count");

        $deleted = $wpdb->query(
            $wpdb->prepare("
                DELETE b FROM $bookings_table b
                JOIN $availability_table a ON b.availability_id = a.availability_id
                WHERE a.session_id = %s
            ", $reference)
        );
        error_log(date('[Y-m-d H:i:s] ') . "Bookings deleted for session $reference: $deleted");

        $updated = $wpdb->query(
            $wpdb->prepare("
                UPDATE $availability_table
                SET status = 'a',
                    hold_until = NULL,
                    session_id = NULL
                WHERE session_id = %s AND status = 'p'
            ", $reference)
        );
        error_log(date('[Y-m-d H:i:s] ') . "Availability rows updated for session $reference: $updated");

        update_providers_availability_spots($reference);

        error_log(date('[Y-m-d H:i:s] ') . "Paystack payment failed for session: $reference");

    } else {
        error_log(date('[Y-m-d H:i:s] ') . "Paystack webhook received unhandled event: $event_type");
    }

    return $response;
}


add_action('rest_api_init', function () {
    register_rest_route('pcp/v1', '/generate_session_id', [
        'methods'  => 'POST',
        'callback' => 'generate_session_id_rest',
        'permission_callback' => function ($request) {
            $nonce = $request->get_header('X-WP-Nonce');
            return wp_verify_nonce($nonce, 'wp_rest');
        }
    ]);
});

function generate_session_id_rest(WP_REST_Request $request) {
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
            return rest_ensure_response([
                'success' => true,
                'data' => ['session_id' => $session_id]
            ]);
        }
    }

    return new WP_REST_Response([
        'success' => false,
        'message' => 'Could not generate unique session ID'
    ], 500);
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
        sales_email VARCHAR(255),
        PRIMARY KEY (provider_id)
    ) $charset_collate;";

    $services_table = $wpdb->prefix . 'services';
    $sql2 = "CREATE TABLE IF NOT EXISTS $services_table (
        service_id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
        provider_id BIGINT(20) UNSIGNED NOT NULL,
        service_name VARCHAR(100) NOT NULL,
        service_cost_provider DECIMAL(10,2) NOT NULL,
        service_cost_main DECIMAL(10,2) NOT NULL,
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
        customer_number VARCHAR(100),
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
        $sales_email = sanitize_email($_POST['sales_email']);
        $api_key = create_api_key();
        $hmac_secret = create_hmac_secret();

        $wpdb->insert($table, [
            'provider_name' => $provider_name,
            'api_key' => $api_key,
            'hmac_secret' => $hmac_secret,
            'base_api_url' => $base_api_url,
            'paystack_subaccount' => $paystack_subaccount,
            'sales_email' => $sales_email,
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
            <tr><th><label for="base_api_url">Base API url</label></th><td><input name="base_api_url" required /></td></tr>
            <tr><th><label for="paystack_subaccount">Paystack Subaccount</label></th><td><input name="paystack_subaccount" required /></td></tr>
            <tr><th><label for="sales_email">Sales Email</label></th><td><input name="sales_email" type="email" required /></td></tr>
        </table>
        <input type="submit" name="add_provider" class="button-primary" value="Add Provider" />
    </form>';

    if (!empty($providers)) {
        echo '<h2>Registered Providers</h2>';
        echo '<form method="post">';
        echo '<table class="widefat">';
        echo '<thead><tr><th>ID</th><th>Name</th><th>Base API url</th><th>Sales Email</th><th>Paystack Subaccount</th><th>API Key</th><th>HMAC Secret</th><th>Active</th><th>Actions</th></tr></thead><tbody>';

        foreach ($providers as $p) {
            $api_key_id = 'api_key_' . $p->provider_id;
            $hmac_id = 'hmac_secret_' . $p->provider_id;

            echo '<tr>';
            echo '<td>' . esc_html($p->provider_id) . '</td>';
            echo '<td>' . esc_html($p->provider_name) . '</td>';
            echo '<td>' . esc_url($p->base_api_url) . '</td>';
            echo '<td>' . esc_html($p->sales_email) . '</td>';
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
    $services_table = $wpdb->prefix . 'services';
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
        $service_cost_provider = floatval($_POST['service_cost']);
        $max_spots = intval($_POST['max_spots']);

        // Round up to nearest 1 after adding 10%
        $service_cost_main = ceil($service_cost_provider * 1.1);

        $existing = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM $services_table WHERE service_name = %s AND provider_id = %d",
            $service_name, $provider_id
        ));

        if ($existing > 0) {
            echo '<div class="notice notice-warning"><p>Service already exists.</p></div>';
        } else {
            $wpdb->insert($services_table, [
                'provider_id'           => $provider_id,
                'service_name'          => $service_name,
                'service_cost_provider' => $service_cost_provider,
                'service_cost_main'     => $service_cost_main,
                'max_spots'             => $max_spots
            ]);

            echo '<div class="updated"><p>Service added.</p></div>';
        }
    }

    // Delete service
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

    // Edit service cost
    if (isset($_POST['edit_service_submit'])) {
        $edit_id = intval($_POST['edit_service_id']);
        $new_cost_provider = floatval($_POST['new_service_cost_provider']);
        $new_cost_main = ceil($new_cost_provider * 1.1);

        $valid = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM $services_table WHERE service_id = %d AND provider_id = %d",
            $edit_id, $provider_id
        ));

        if ($valid) {
            $wpdb->update(
                $services_table,
                [
                    'service_cost_provider' => $new_cost_provider,
                    'service_cost_main'     => $new_cost_main
                ],
                ['service_id' => $edit_id]
            );
            echo '<div class="updated"><p>Service cost updated.</p></div>';
        }
    }

    // Add form
    echo '<form method="post" style="margin-bottom: 1em;">';
    echo '<input type="text" name="service_name" placeholder="New Service Name" required> ';
    echo '<input type="number" name="service_cost" placeholder="Cost (Provider)" step="0.01" required> ';
    echo '<input type="number" name="max_spots" placeholder="Max Spots" required> ';
    echo '<input type="submit" name="add_service_submit" class="button button-primary" value="Add Service">';
    echo '</form>';

    // Display services
    $services = $wpdb->get_results($wpdb->prepare(
        "SELECT * FROM $services_table WHERE provider_id = %d",
        $provider_id
    ));

    if ($services) {
        echo '<table class="widefat striped">';
        echo '<thead><tr><th>Service Name</th><th>Provider Cost</th><th>Main Cost</th><th>Max Spots</th><th>Actions</th></tr></thead><tbody>';

        foreach ($services as $service) {
            echo '<tr>';
            echo '<td>' . esc_html($service->service_name) . '</td>';
            echo '<td>R' . esc_html(number_format($service->service_cost_provider, 2)) . '</td>';
            echo '<td>R' . esc_html(number_format($service->service_cost_main, 2)) . '</td>';
            echo '<td>' . esc_html($service->max_spots) . '</td>';
            echo '<td>';
            echo '<form method="post" style="display:inline-block;margin-right:10px;">';
            echo '<input type="hidden" name="delete_service_id" value="' . intval($service->service_id) . '">';
            echo '<input type="submit" class="button button-secondary" value="Delete">';
            echo '</form>';

            echo '<form method="post" style="display:inline-block;">';
            echo '<input type="hidden" name="edit_service_id" value="' . intval($service->service_id) . '">';
            echo '<input type="number" name="new_service_cost_provider" placeholder="New Cost" step="0.01" style="width:100px;"> ';
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

    // Get provider
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
        echo '<div class="notice notice-warning"><p>Undo complete. Removed ' . $deleted_count . ' slot(s).</p></div>';
    }

    $services = $wpdb->get_results($wpdb->prepare(
        "SELECT * FROM {$services_table} WHERE provider_id = %d",
        $provider_id
    ));

    if (empty($services)) {
        echo '<p><em>No services found for this provider.</em></p>';
        echo '</div>';
        return;
    }

    $days = ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'];

    foreach ($services as $service) {
        $service_id = $service->service_id;
        $service_name = $service->service_name;
        $max_spots = $service->max_spots;

        echo '<h2 style="border-bottom: 2px solid black; padding-bottom: 5px; margin-bottom: 10px;">' . esc_html($service_name) . '</h2>';

        // We'll store undo data here to show undo button next to Add button
        $undo_data = '';

        if (isset($_POST['add_time_slot_' . $service_id])) {
            $from_time = sanitize_text_field($_POST['from_time']);
            $to_time = sanitize_text_field($_POST['to_time']);
            $from_date = sanitize_text_field($_POST['from_date']);
            $to_date = sanitize_text_field($_POST['to_date']);

            $selected_days = [];
            foreach ($days as $day) {
                if (!empty($_POST[$day])) {
                    $selected_days[] = $day;
                }
            }

            $errors = [];

            // Validate times
            $ft_parts = explode(':', $from_time);
            $tt_parts = explode(':', $to_time);
            if (count($ft_parts) !== 2 || count($tt_parts) !== 2) {
                $errors[] = "Please enter valid From Time and To Time.";
            } else {
                $from_minutes = intval($ft_parts[0]) * 60 + intval($ft_parts[1]);
                $to_minutes = intval($tt_parts[0]) * 60 + intval($tt_parts[1]);
                if ($from_minutes >= $to_minutes) {
                    $errors[] = "From Time must be earlier than To Time.";
                }
            }

            // Validate dates
            if (!$from_date || !$to_date || strtotime($from_date) === false || strtotime($to_date) === false) {
                $errors[] = "Please enter valid From Date and To Date.";
            } else {
                if (strtotime($from_date) > strtotime($to_date)) {
                    $errors[] = "From Date must be earlier than or equal to To Date.";
                }
                if ((strtotime($to_date) - strtotime($from_date)) > (90 * 86400)) {
                    $errors[] = "Date range cannot exceed 3 months.";
                }
            }

            if (empty($selected_days)) {
                $errors[] = "Please select at least one day.";
            }

            if (empty($errors)) {
                $inserted_count = 0;
                $skipped_due_to_max = 0;
                $slot_length = $to_minutes - $from_minutes;
                $inserted_slots = [];

                $date = new DateTime($from_date);
                $end = new DateTime($to_date);

                while ($date <= $end) {
                    $day_name = strtolower($date->format('l'));
                    if (in_array($day_name, $selected_days)) {
                        $available_date = $date->format('Y-m-d');

                        $existing_count = $wpdb->get_var($wpdb->prepare(
                            "SELECT COUNT(*) FROM {$availability_table}
                             WHERE service_id = %d AND available_date = %s AND time_slot = %s",
                            $service_id, $available_date, $from_time
                        ));

                        if ($existing_count >= $max_spots) {
                            $skipped_due_to_max++;
                        } else {
                            $slots_to_add = $max_spots - $existing_count;
                            for ($i = 0; $i < $slots_to_add; $i++) {
                                $success = $wpdb->insert($availability_table, [
                                    'service_id' => $service_id,
                                    'available_date' => $available_date,
                                    'time_slot' => $from_time,
                                    'time_slot_length_min' => $slot_length,
                                    'status' => 'a'
                                ]);
                                if ($success) {
                                    $inserted_count++;
                                    $inserted_slots[] = [
                                        'service_id' => $service_id,
                                        'available_date' => $available_date,
                                        'time_slot' => $from_time
                                    ];
                                }
                            }
                        }
                    }
                    $date->modify('+1 day');
                }

                if ($inserted_count > 0) {
                    $undo_data = base64_encode(json_encode($inserted_slots));
                    echo '<div class="notice notice-success"><p>Inserted ' . $inserted_count . ' slot(s).';
                    if ($skipped_due_to_max > 0) {
                        echo ' ' . $skipped_due_to_max . ' date(s) skipped due to reaching max spots.';
                    }
                    echo '</p></div>';
                }

                if ($skipped_due_to_max > 0 && $inserted_count == 0) {
                    // Show warning if only skipped (no inserts)
                    echo '<div class="notice notice-warning"><p>' . $skipped_due_to_max . ' date(s) skipped due to reaching max spots.</p></div>';
                }
            } else {
                foreach ($errors as $error) {
                    echo '<div class="notice notice-error"><p>' . esc_html($error) . '</p></div>';
                }
            }
        }

        // Form with Add and Undo buttons side by side
        echo '<form method="post" onsubmit="return confirm(\'Are you sure you want to add these time slots?\');" style="margin-bottom: 1em;">';

        echo '<div style="display: flex; gap: 20px; align-items: center; margin-bottom: 15px;">';
        echo '<div><label style="font-weight:bold;">From Time<br><input type="time" name="from_time" required></label></div>';
        echo '<div><label style="font-weight:bold;">To Time<br><input type="time" name="to_time" required></label></div>';
        echo '</div>';

        echo '<div style="display: flex; gap: 20px; align-items: center; margin-bottom: 15px;">';
        echo '<div><label style="font-weight:bold;">From Date<br><input type="date" name="from_date" required></label></div>';
        echo '<div><label style="font-weight:bold;">To Date<br><input type="date" name="to_date" required></label></div>';
        echo '</div>';

        echo '<div style="font-weight: bold; margin-bottom: 7px;">Days of the Week<br>';
        foreach ($days as $day) {
            echo '<label style="margin-right: 15px; margin-top: 7px;">';
            echo '<input type="checkbox" name="' . esc_attr($day) . '"> ' . ucfirst($day);
            echo '</label>';
        }
        echo '</div>';

        // Buttons container: Add and Undo side by side
        echo '<div style="display: flex; gap: 10px; align-items: center;">';

        // Add Time Slot button inside main form
        echo '<input type="submit" class="button button-primary" name="add_time_slot_' . esc_attr($service_id) . '" value="Add Time Slot">';

        // Close main form
        echo '</form>';

        // Undo button in its own form, inline style for side by side
        if (!empty($undo_data)) {
            echo '<form method="post" style="margin:0; display: inline-block;">';
            echo '<input type="hidden" name="undo_slots" value="' . esc_attr($undo_data) . '">';
            echo '<input type="submit" name="undo_action" class="button button-secondary" value="Undo">';
            echo '</form>';
        }

        echo '</div>';
    }

    

    echo '<div style="border-top: 2px solid black; margin: 20px 0; padding-top: 10px;">';
    echo do_shortcode('[provider_admin_custom_calendar]');
    echo '</div>';
}

add_action('admin_menu', 'provider_bookings_dashboard_menu');
function provider_bookings_dashboard_menu() {
    if (current_user_can('provider')) {
        add_menu_page(
            'Bookings Dashboard',
            'Bookings',
            'read',
            'provider-bookings-dashboard',
            'provider_bookings_dashboard_page',
            'dashicons-calendar-alt',
            4
        );
    }
}

function provider_bookings_dashboard_page() {
    global $wpdb;

    $providers_table   = $wpdb->prefix . 'provider_sites';
    $services_table    = $wpdb->prefix . 'services';
    $availability_table = $wpdb->prefix . 'availability';
    $bookings_table    = $wpdb->prefix . 'bookings';

    $current_user = wp_get_current_user();
    $username     = $current_user->user_login;

    // Get provider
    $provider = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM {$providers_table} WHERE provider_name = %s",
        $username
    ));

    if (!$provider) {
        echo '<div class="notice notice-error"><p>Provider not found.</p></div>';
        return;
    }

    $provider_id = $provider->provider_id;

    // Get services for provider
    $services = $wpdb->get_results($wpdb->prepare(
        "SELECT * FROM {$services_table} WHERE provider_id = %d",
        $provider_id
    ));

    // Handle form filters
    $from_date = isset($_POST['from_date']) && $_POST['from_date'] !== ''
        ? sanitize_text_field($_POST['from_date'])
        : date('Y-m-d');
    $to_date   = isset($_POST['to_date']) && $_POST['to_date'] !== ''
        ? sanitize_text_field($_POST['to_date'])
        : null;
    $service_filter = isset($_POST['service_id']) ? intval($_POST['service_id']) : 0;

    // New booked_by filter: 0 = All, 1 = Website (booked_by_main=1), 2 = Private (booked_by_main=0)
    $booked_by_filter = isset($_POST['booked_by']) ? intval($_POST['booked_by']) : 0;

    // Build SQL conditions
    $conditions = [];
    $params     = [];

    $conditions[] = "s.provider_id = %d";
    $params[]     = $provider_id;

    if ($from_date) {
        $conditions[] = "a.available_date >= %s";
        $params[]     = $from_date;
    }
    if ($to_date) {
        $conditions[] = "a.available_date <= %s";
        $params[]     = $to_date;
    }
    if ($service_filter > 0) {
        $conditions[] = "s.service_id = %d";
        $params[]     = $service_filter;
    }

    // Add booked_by filter condition
    if ($booked_by_filter === 1) {
        // Website bookings only
        $conditions[] = "b.booked_by_main = 1";
    } elseif ($booked_by_filter === 2) {
        // Private bookings only
        $conditions[] = "b.booked_by_main = 0";
    }
    // 0 = all, no condition added

    $where_sql = "WHERE " . implode(" AND ", $conditions);

    // Query bookings
    $query = "
        SELECT 
            a.available_date AS booking_date,
            s.service_name,
            b.booked_by_main,
            b.customer_name,
            b.customer_email,
            a.session_id
        FROM {$bookings_table} b
        INNER JOIN {$availability_table} a ON b.availability_id = a.availability_id
        INNER JOIN {$services_table} s ON a.service_id = s.service_id
        $where_sql
        ORDER BY a.available_date ASC, s.service_name ASC
    ";

    $results = $wpdb->get_results($wpdb->prepare($query, $params));

    // Render page
    echo '<div class="wrap"><h1>Bookings</h1>';

    // Filter form
    echo '<form method="post" style="margin-bottom:20px;">';
    echo '<label>From Date: <input type="date" name="from_date" value="' . esc_attr($from_date) . '"></label> ';
    echo '<label>To Date: <input type="date" name="to_date" value="' . esc_attr($to_date) . '"></label> ';
    echo '<label>Service: <select name="service_id">';
    echo '<option value="0">All Services</option>';
    foreach ($services as $service) {
        $selected = $service_filter == $service->service_id ? 'selected' : '';
        echo '<option value="' . intval($service->service_id) . '" ' . $selected . '>' . esc_html($service->service_name) . '</option>';
    }
    echo '</select></label> ';

    // Booked By dropdown
    echo '<label>Booked By: <select name="booked_by">';
    echo '<option value="0"' . selected($booked_by_filter, 0, false) . '>All</option>';
    echo '<option value="1"' . selected($booked_by_filter, 1, false) . '>Website</option>';
    echo '<option value="2"' . selected($booked_by_filter, 2, false) . '>Private</option>';
    echo '</select></label> ';

    echo '<input type="submit" class="button button-primary" value="Filter">';
    echo '</form>';

    // Table
    if ($results) {
        echo '<table class="widefat fixed striped">';
        echo '<thead><tr>';
        echo '<th>Date</th>';
        echo '<th>Service</th>';
        echo '<th>Booked By Website</th>';
        echo '<th>Customer Name</th>';
        echo '<th>Customer Email/Phone number</th>';
        echo '<th>Reference</th>';
        echo '</tr></thead><tbody>';

        foreach ($results as $row) {
            echo '<tr>';
            echo '<td>' . esc_html($row->booking_date) . '</td>';
            echo '<td>' . esc_html($row->service_name) . '</td>';
            echo '<td>' . ($row->booked_by_main ? 'Yes' : 'No') . '</td>';
            echo '<td>' . esc_html($row->customer_name) . '</td>';
            echo '<td>' . esc_html($row->customer_email) . '</td>';
            echo '<td>' . esc_html($row->session_id) . '</td>';
            echo '</tr>';
        }

        echo '</tbody></table>';
    } else {
        echo '<p>No bookings found for the selected filters.</p>';
    }

    echo '</div>';
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

add_action('rest_api_init', function () {
    register_rest_route('api/v1', '/provider_get_all_service_spots_availability', [
        'methods' => 'GET',
        'callback' => 'provider_get_all_service_spots_availability',
        'permission_callback' => '__return_true', // Custom auth will be handled in function
    ]);
});

//For provider to get all the available spots for a service
function provider_get_all_service_spots_availability(WP_REST_Request $request){
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

    $availability_results = $wpdb->get_results(
            $wpdb->prepare("
                SELECT status, provider_db_id AS id, available_date AS date, time_slot, time_slot_length_min 
                FROM $availability_table                
                WHERE service_id = %d
            ", $service_id),
            ARRAY_A
        );

    $availability = [
        'service_name' => $service_name,
        'dates_availability' => $availability_results,
    ];

    return new WP_REST_Response($availability, 200);
}

//==================
//PROVIDER ENDPOINTS
//==================

//ADD UPDATE
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
            p.sales_email,
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
                'provider_id' => $row->provider_id,
                'provider_name' => $row->provider_name,
                'api_key' => $row->api_key,
                'hmac_secret' => $row->hmac_secret,
                'base_api_url' => $row->base_api_url,
                'sales_email' => $row->sales_email,
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

//Function to use whenever a spot is b
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

function create_and_send_email($to, $session_id){
    create_and_send_provider_emails($session_id);  
    global $wpdb;

    $query = $wpdb->prepare("
        SELECT 
            a.available_date,
            a.time_slot,
            a.time_slot_length_min,
            a.service_id,
            s.service_name,
            s.provider_id,
            p.provider_name
        FROM {$wpdb->prefix}availability a
        INNER JOIN {$wpdb->prefix}services s ON a.service_id = s.service_id
        INNER JOIN {$wpdb->prefix}provider_sites p ON s.provider_id = p.provider_id
        WHERE a.session_id = %s
    ", $session_id);

    $results = $wpdb->get_results($query, ARRAY_A);

    if (!$results) {
        error_log("No availability found for session_id: $session_id");
        return;
    }


    $message = generate_html_email_from_availability($results, $session_id);

    $subject = 'Tickets!';
    $headers = array('Content-Type: text/html; charset=UTF-8');

    $sent = wp_mail($to, $subject, $message, $headers);
    if (!$sent) {
        error_log("wp_mail failed to send to $to");
    }
}

function generate_html_email_from_availability($availability_rows, $session_id) {
    $grouped = [];

    // Group by provider and then service
    foreach ($availability_rows as $row) {
        $provider = $row['provider_name'];
        $service = $row['service_name'];

        // Calculate to_time
        $start_time = DateTime::createFromFormat('H:i:s', $row['time_slot']);
        $from_time = $start_time->format('H:i');
        $start_time->modify("+" . $row['time_slot_length_min'] . " minutes");
        $to_time = $start_time->format('H:i');

        $grouped[$provider][$service][] = [
            'available_date' => $row['available_date'],
            'from_time' => $from_time,
            'to_time' => $to_time,
        ];
    }

    // Start HTML output
    $html = '<html><body style="font-family: Arial, sans-serif;">';
    $html .= '<h2 style="color:#2c3e50;">Tickets</h2>';
        $html .= '<p style="font-size:14px;color:#555;"><strong>Reference code:</strong> ' . esc_html($session_id) . '</p>';

    foreach ($grouped as $provider_name => $services) {
        $html .= "<h3 style='color:#2980b9;'>$provider_name</h3>";

        foreach ($services as $service_name => $tickets) {
            $html .= "<h4 style='color:#27ae60;margin-left:20px;'>$service_name</h4>";
            $html .= "<ul style='margin-left:40px;'>";

            foreach ($tickets as $ticket) {
                $html .= "<li><strong>Date:</strong> {$ticket['available_date']} | 
                          <strong>Time:</strong> {$ticket['from_time']} - {$ticket['to_time']}</li>";
            }

            $html .= "</ul>";
        }
    }

    $html .= '</body></html>';

    return $html;
}

function create_and_send_provider_emails($session_id){
    global $wpdb;

    // Step 1: Get all unique providers in the session
    $providers = $wpdb->get_results($wpdb->prepare("
        SELECT DISTINCT p.provider_id, ps.sales_email
        FROM {$wpdb->prefix}provider_sites ps
        INNER JOIN {$wpdb->prefix}services s ON s.provider_id = ps.provider_id
        INNER JOIN {$wpdb->prefix}availability a ON a.service_id = s.service_id
        INNER JOIN {$wpdb->prefix}provider_sites p ON p.provider_id = ps.provider_id
        WHERE a.session_id = %s
    ", $session_id));

    if (!$providers) {
        error_log("No providers found for session_id: $session_id");
        return;
    }

    // Step 2: Loop through each provider
    foreach ($providers as $provider) {
        $provider_id = $provider->provider_id;
        $sales_email = $provider->sales_email;

        // Fetch only this provider's availability rows for the session
        $availability_rows = $wpdb->get_results($wpdb->prepare("
            SELECT 
                a.available_date,
                a.time_slot,
                a.time_slot_length_min,
                a.provider_db_id,
                a.service_id,
                a.status,
                s.service_name,
                p.provider_name
            FROM {$wpdb->prefix}availability a
            INNER JOIN {$wpdb->prefix}services s ON a.service_id = s.service_id
            INNER JOIN {$wpdb->prefix}provider_sites p ON s.provider_id = p.provider_id
            WHERE a.session_id = %s AND s.provider_id = %d
        ", $session_id, $provider_id), ARRAY_A);

        if (!$availability_rows) {
            error_log("No availability rows for provider_id $provider_id and session_id $session_id");
            continue;
        }

        // Generate and send the email
        $message = generate_html_email_for_provider($availability_rows, $session_id);

        $subject = 'Booked Tickets Receipt!';
        $headers = array('Content-Type: text/html; charset=UTF-8');

        $sent = wp_mail($sales_email, $subject, $message, $headers);
        if (!$sent) {
            error_log("wp_mail failed to send provider email to $sales_email for provider_id $provider_id");
        }
    }
}



function generate_html_email_for_provider($availability_rows, $session_id) {
    $grouped = [];

    // Group by service
    foreach ($availability_rows as $row) {
        $service = $row['service_name'];

        // Calculate from_time and to_time
        $start_time = DateTime::createFromFormat('H:i:s', $row['time_slot']);
        $from_time = $start_time->format('H:i');
        $start_time->modify("+" . $row['time_slot_length_min'] . " minutes");
        $to_time = $start_time->format('H:i');

        $grouped[$service][] = [
            'available_date' => $row['available_date'],
            'from_time' => $from_time,
            'to_time' => $to_time,
            'provider_db_id' => $row['provider_db_id'],
            'status' => $row['status'],
        ];
    }

    // Start HTML output
    $html = '<html><body style="font-family: Arial, sans-serif;">';
    $html .= '<h2 style="color:#2c3e50;">Tickets Summary</h2>';
    $html .= '<p style="font-size:14px;color:#555;"><strong>Reference Code:</strong> ' . esc_html($session_id) . '</p>';

    foreach ($grouped as $service_name => $tickets) {
        $html .= "<h4 style='color:#27ae60;margin-left:20px;'>Service: " . esc_html($service_name) . "</h4>";
        $html .= "<table style='margin-left:40px;border-collapse:collapse;width:80%;'>";
        $html .= "<thead><tr>
                    <th style='border:1px solid #ccc;padding:8px;'>ID</th>
                    <th style='border:1px solid #ccc;padding:8px;'>Date</th>
                    <th style='border:1px solid #ccc;padding:8px;'>Time</th>
                    <th style='border:1px solid #ccc;padding:8px;'>Status</th>
                  </tr></thead><tbody>";

        foreach ($tickets as $ticket) {
            $html .= "<tr>
                        <td style='border:1px solid #ccc;padding:8px;'>{$ticket['provider_db_id']}</td>
                        <td style='border:1px solid #ccc;padding:8px;'>{$ticket['available_date']}</td>
                        <td style='border:1px solid #ccc;padding:8px;'>{$ticket['from_time']} - {$ticket['to_time']}</td>
                        <td style='border:1px solid #ccc;padding:8px;'>{$ticket['status']}</td>
                      </tr>";
        }

        $html .= "</tbody></table><br/>";
    }

    $html .= '</body></html>';

    return $html;
}





//=========
//CRON
//=========

function pcp_release_expired_pending() {
    global $wpdb;
    $availability_table = "{$wpdb->prefix}availability";
    $bookings_table = "{$wpdb->prefix}bookings";

    // Get expired pending availability slots
    $expired_rows = $wpdb->get_results("
        SELECT availability_id, session_id 
        FROM $availability_table 
        WHERE status = 'p' AND hold_until < NOW()
    ");

    $touched_sessions = [];
    $verified_sessions = [];  // Cache to store verification results by session_id
    $emails_sent = [];        // To ensure email sent once per session_id
    $customer_emails = [];    // Cache customer emails per session_id

    foreach ($expired_rows as $row) {
        $availability_id = $row->availability_id;
        $session_id = $row->session_id;

        $has_booking = $wpdb->get_var($wpdb->prepare("
            SELECT COUNT(*) FROM $bookings_table 
            WHERE availability_id = %d
        ", $availability_id));

        if ($has_booking && $session_id) {
            // Cache customer email per session_id to avoid redundant queries
            if (!isset($customer_emails[$session_id])) {
                $customer_emails[$session_id] = $wpdb->get_var($wpdb->prepare("
                    SELECT customer_email FROM $bookings_table 
                    WHERE availability_id = %d LIMIT 1
                ", $availability_id));
            }
            $customer_email = $customer_emails[$session_id];

            if (!isset($verified_sessions[$session_id])) {
                // Verify payment once per session_id
                $verification_result = pcp_manual_paystack_verify($session_id);
                $verified_sessions[$session_id] = $verification_result;

                // Send email only once if verification successful and email exists
                if ($verification_result['success'] && $customer_email && !isset($emails_sent[$session_id])) {
                    create_and_send_email($customer_email, $session_id);
                    $emails_sent[$session_id] = true;
                }
            } else {
                $verification_result = $verified_sessions[$session_id];
            }

            if (!$verification_result['success']) {
                if (in_array($verification_result['status'], ['abandoned', 'failed', 'cancelled'])) {
                    // Release availability slot and delete booking
                    $wpdb->query($wpdb->prepare("
                        UPDATE $availability_table
                        SET session_id = NULL,
                            status = 'a',
                            hold_until = NULL
                        WHERE availability_id = %d
                        AND status = 'p'
                    ", $availability_id));

                    $wpdb->delete($bookings_table, [
                        'availability_id' => $availability_id
                    ], ['%d']);


                    $touched_sessions[] = $session_id;
                }
                else
                {
                    continue;
                }
                continue;
            } else {
                // Payment successful, mark as booked
                $wpdb->query($wpdb->prepare("
                    UPDATE $availability_table
                    SET status = 'b'
                    WHERE availability_id = %d
                    AND status = 'p'
                ", $availability_id));

                $touched_sessions[] = $session_id;
            }

            continue;
        }

        // No booking or session, release slot
        $wpdb->query($wpdb->prepare("
            UPDATE $availability_table
            SET session_id = NULL,
                status = 'a',
                hold_until = NULL
            WHERE availability_id = %d
            AND status = 'p'
        ", $availability_id));

        if (!empty($session_id)) {
            $touched_sessions[] = $session_id;
        }
    }

    // Remove duplicate session ids
    $touched_sessions = array_unique($touched_sessions);

    // Update availability spots for all touched sessions
    foreach ($touched_sessions as $sess_id) {
        update_providers_availability_spots($sess_id);
    }
}

function pcp_manual_paystack_verify($reference) {
    global $wpdb;

    $paystack_secret = $wpdb->get_var("
        SELECT paystack_api_key_secret
        FROM {$wpdb->prefix}paystack_info
        WHERE id = 1
    ");

    $curl = curl_init();

    curl_setopt_array($curl, array(
        CURLOPT_URL => "https://api.paystack.co/transaction/verify/" . $reference,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => array(
            "Authorization: Bearer $paystack_secret",
            "Cache-Control: no-cache",
        ),
        CURLOPT_TIMEOUT => 15,
    ));

    $response = curl_exec($curl);
    $err = curl_error($curl);
    curl_close($curl);

    if ($err) {
        error_log("Paystack cURL error: " . $err);
        return ['success' => false, 'status' => 'unknown', 'error' => $err];
    }

    $result = json_decode($response, true);
    if (!$result || !isset($result['status']) || !$result['data']) {
        return ['success' => false, 'status' => 'unknown', 'error' => 'Invalid API response'];
    }

    $paystack_status = $result['data']['status'];

    error_log($paystack_status);

    if ($paystack_status === 'success') {
        return ['success' => true, 'status' => 'success'];
    } elseif (in_array($paystack_status, ['abandoned', 'failed', 'cancelled'])) {
        return ['success' => false, 'status' => $paystack_status];
    } else {
        // ongoing, pending, processing        
        return ['success' => false, 'status' => $paystack_status];
    }
    
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




