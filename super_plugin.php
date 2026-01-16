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

add_action('admin_enqueue_scripts', 'enqueue_chartjs');

function enqueue_chartjs($hook) {
    if ($hook !== 'toplevel_page_provider-payments-dashboard') return;
    wp_enqueue_script('chartjs','https://cdn.jsdelivr.net/npm/chart.js',[],null,true);
}

/*
add_shortcode('book_btn_redirect', 'pcp_book_btn_redirect_shortcode');

function pcp_book_btn_redirect_shortcode($atts = []) {
    $atts = array_change_key_case((array) $atts, CASE_LOWER);

    $book_btn_atts = shortcode_atts(
        array(
            'provider_id' => '0',
        ), 
        $atts
    );

    $provider_id = intval($book_btn_atts['provider_id']);
    $redirect_url = add_query_arg('provider_id', $provider_id, site_url('/booking'));

    return '<a href="' . esc_url($redirect_url) . '" class="pcp-book-btn">Book Now!</a>';
}
*/

add_shortcode('ticket_verification', 'pcp_ticket_verification_shortcode');

function pcp_ticket_verification_shortcode() {
    if (!isset($_GET['reference'])) {
        return "<p>No reference number provided.</p>";
    }

    global $wpdb;

    $session_id = sanitize_text_field($_GET['reference']);

    // Query availability
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
        return "<p>No tickets found for reference <strong>" . esc_html($session_id) . "</strong>.</p>";
    }

    // Group results by provider > service
    $grouped = [];
    foreach ($results as $row) {
        $provider = $row['provider_name'];
        $service = $row['service_name'];

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


    $html  = '<div style="font-family: Arial, sans-serif; display:flex; justify-content:center; margin-top:30px;">';
    $html .= '<div style="max-width:700px; width:100%; background:#fff; padding:20px; border-radius:10px; box-shadow:0 4px 12px rgba(0,0,0,0.1); text-align:center;">';
    $html .= '<h2 style="color:#2c3e50;">Tickets</h2>';
    $html .= '<p style="font-size:14px;color:#555;"><strong>Reference number:</strong> ' . esc_html($session_id) . '</p>';

    foreach ($grouped as $provider_name => $services) {
        $html .= "<h3 style='color:#2980b9; margin-bottom:5px;'>" . esc_html($provider_name) . "</h3>";

        foreach ($services as $service_name => $tickets) {
            $html .= "<h4 style='color:#27ae60; margin-bottom:3px;'>" . esc_html($service_name) . "</h4>";
            $html .= "<ul style='list-style-position: inside; padding-left:0; margin: 10px auto; display:inline-block; text-align:left;'>";

            foreach ($tickets as $ticket) {
                $ticket_date = esc_html($ticket['available_date']);
                $ticket_from = esc_html($ticket['from_time']);
                $ticket_to   = esc_html($ticket['to_time']);

                $html .= "<li><strong>Date:</strong> {$ticket_date} | <strong>Time:</strong> {$ticket_from} - {$ticket_to}</li>";
            }

            $html .= "</ul>";
        }
    }

    $html .= '</div>';
    $html .= '</div>';

    return $html;
}



add_shortcode('custom_calendar', 'pcp_custom_calendar_shortcode');

function pcp_custom_calendar_shortcode() {
    global $wpdb;

    $providers = $wpdb->get_results("SELECT * FROM {$wpdb->prefix}provider_sites");     

    ob_start(); ?>
    <div class="calendar-scroll-wrapper">
        <div id="calendar-container">
            <div id="timer-display">10:00</div>
                <div class="selection-rows">
                <label for="provider-select">Select Provider:</label>
                <select id="provider-select">
                    <option value="">-- Select Provider --</option>
                    <?php foreach ($providers as $provider): ?>
                        <option value="<?= esc_attr($provider->provider_id); ?>">
                            <?= esc_html($provider->provider_name); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="selection-rows">
                <label for="service-select">Select Service:  </label>
                <select id="service-select" disabled>
                    <option value="">-- Select Provider First --</option>
                </select>
            </div>
            <div id="service_description_div" style="display: none;">
                <h6 id=service_description_header>Service Description</h6>
                <p id=service_description>Text</p>
            </div>
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
            <h2>Booking Summary</h4>    
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
        <div id="callback-ui" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background-color:rgba(0,0,0,0.6); z-index:9999; align-items:center; justify-content:center;">
            <div style="background:#fff; padding:20px; border-radius:8px; max-width:400px; width:90%; box-shadow:0 4px 10px rgba(0,0,0,0.2); text-align:center;">
                <h3 style="margin:0 0 5px 0;">Payment/Booking Succesful!</h3>
                <p style="margin:0 0 5px 0;">Your payment/booking was successful! Thank you for your support and see you on the Trail.</p>
                <img src="https://capekelpforesttrail.co.za/wp-content/uploads/2025/09/cropped-cropped-logo-latest-transparent-bg-scaled-1.jpg" 
                    alt="Logo"
                    style="max-width:100%; height:auto; display:block; margin:0px auto;">
                <button id="callback-ui-ok" style="padding:10px 30px;">Ok</button>
            </div>
        </div>
    <div id="timer-ui" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background-color:rgba(0,0,0,0.6); z-index:9999; align-items:center; justify-content:center;">
        <div style="background:#fff; padding:20px; border-radius:8px; max-width:400px; width:90%; box-shadow:0 4px 10px rgba(0,0,0,0.2);">
            <h3 style="margin:0 0 5px 0;">Are you still booking?</h3>
            <button id="timer-renew" style="padding:8px 12px;">Extend Session</button>
            <button id="timer-cancel" style="padding:8px 12px;">Cancel Session</button>
        </div>
    </div>
    <div id="timer-done-ui" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background-color:rgba(0,0,0,0.6); z-index:9999; align-items:center; justify-content:center;">
        <div style="background:#fff; padding:20px; border-radius:8px; max-width:400px; width:90%; box-shadow:0 4px 10px rgba(0,0,0,0.2);">
            <h3 style="margin:0 0 5px 0;">Session cannot be extended any further. Pleasex finish booking.</h3>
            <button id="timer-done-ok" style="padding:8px 12px;">Ok</button>
        </div>
    </div>
    <div id="client-info-modal" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background-color:rgba(0,0,0,0.6); z-index:9999; align-items:center; justify-content:center;">
        <div style="background:#fff; padding:20px; border-radius:8px; max-width:400px; width:90%; box-shadow:0 4px 10px rgba(0,0,0,0.2);">
            <h3 style="margin:0 0 5px 0;">Enter Details to continue</h3>
            <label for="client-name">Name:</label><br>
            <input type="text" id="client-name" style="width:100%; padding:8px; margin-bottom:10px;"><br>
            <label for="client-name">Phone Number:</label><br>
            <input type="text" id="client-number" style="width:100%; padding:8px; margin-bottom:10px;"><br>
            <label for="client-name">Email:</label><br>
            <input type="email" id="client-email" style="width:100%; padding:8px;"><br><br>
            <button id="submit-client-info" style="padding:8px 12px;">Continue</button>
            <button id="cancel-client-info" style="padding:8px 12px; background:#ccc; margin-left:10px;">Cancel</button>

            <p id="redirect-message" style="margin-top:15px; font-weight:bold; color:green; display:none;">You will be redirected shortly.</p>

            <div style="font-size:12px;color:#888;margin-top:20px;;">Disclaimer: There is a non-refundable admin fee included in your payment - For cancellations and refunds of activities, please contact your chosen service provider directly.</div>
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
        'permission_callback' => '__return_true', // public endpoint, no auth needed
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
        "SELECT service_id, service_name, service_cost_main, service_description FROM $services_table WHERE provider_id = %d",
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

            <label class= "hiddenService" for="service-select">Select Service:</label>
            <select class= "hiddenService" id="service-select">
                <option value="">-- Select Service --</option>
                <?php foreach ($services as $service): ?>
                    <option value="<?= esc_attr($service->service_id); ?>">
                        <?= esc_html($service->service_name); ?>
                    </option>
                <?php endforeach; ?>
            </select>

            <label class = "selectModeLabel" for="mode_selector">Select Mode:</label>
            <select id="mode_selector">
                <option value="bookingMode">Booking</option>
                <option value="editMode">Edit</option>
                <option value="deleteMode">Delete</option>
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
        const modeSelect = document.getElementById("mode_selector")

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

        //Function got an extenstion, no longer just triggerBooking, now it is used to trigger delete or update
        function triggerBooking(slot, btn, startTime, endTimeStr, dateKey){
            let currentMode = modeSelect.value;
            currentSelectedId = -1;
            btn.disabled = true;
            let selectedAvailabilityId = null;
            const all_spots_info = JSON.parse(btn.dataset.spots_info);

            if(currentMode == "bookingMode")
            {
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
                                    btn.disabled = false;
                                    
                                }
            }
            if(currentMode == "deleteMode")
            {
                let id = all_spots_info[0].availability_id;                
                
                let confirmed2 = confirm(`Are you sure you want to delete this timeslot?`);

                if(!confirmed2)
                {
                    btn.disabled = false;
                    return;
                }
                else
                {
                    let spotsBooked = all_spots_info.filter(spot => spot.status === "b").length;
                    if(spotsBooked > 0)
                    {
                        let confirmed = confirm(`There are ${spotsBooked} bookings for this date, are you sure you want to delete them?`);
                        if (!confirmed) {
                            btn.disabled = false;
                            return;
                        }
                        else
                        {
                            deleteTimeSlot(id);
                        }
                    }
                    else
                    {
                        deleteTimeSlot(id);
                    }
                }

            }
            if(currentMode == "editMode")
            {

            }
            
        }

        function modeSelectStyling()
        {
            const allBtns = document.querySelectorAll('.book-btn');
            const mode = modeSelect.value;

        allBtns.forEach(btn => {
            if (mode === "bookingMode") {
                btn.textContent = "Book";
                btn.style.backgroundColor = "#4caf50";
            } else if (mode === "deleteMode") {                                           
                btn.textContent = "Delete";
                btn.style.backgroundColor = "red";
            } else if (mode === "editMode") {
                btn.textContent = "Edit";
                btn.style.backgroundColor = "blue";
            }
        });
        }

        function deleteTimeSlot(id)
        {
            fetch(rest_object.rest_url + 'admin/delete_time_slot', {
                                    method: 'POST',
                                    headers: {
                                        'Content-Type': 'application/json',
                                        'X-WP-Nonce': rest_object.nonce,
                                    },
                                    body: JSON.stringify({
                                        availability_id: id,
                                    }),
                                })
                                .then(res => res.json())
                                .then(data => {
                                    if (data.success) {
                                        updateCalendar();                                    
                                        alert(data.message);                                        
                                    } else {
                                        alert("Error: " + (data.message || 'Unknown error'));
                                    }
                                })
                                .catch(err => {
                                    alert('An unexpected error occurred.');
                                });
        }

    function generateCalendar(year, month, slotsData = {}) {
    const calendarBody = document.getElementById("calendar-body");
    calendarBody.innerHTML = "";

    let firstDay = new Date(year, month, 1).getDay();
    firstDay = firstDay === 0 ? 6 : firstDay - 1;

    const daysInMonth = new Date(year, month + 1, 0).getDate();

    for (let i = 0; i < firstDay; i++) {
      const empty = document.createElement("div");
      empty.classList.add("day-cell", "empty");
      calendarBody.appendChild(empty);
    }

    for (let day = 1; day <= daysInMonth; day++) {
      const dayCell = document.createElement("div");
      dayCell.classList.add("day-cell");

      const isToday =
        year === today.getFullYear() &&
        month === today.getMonth() &&
        day === today.getDate();
      if (isToday) {
        dayCell.classList.add("today");
      }

      const dayNumber = document.createElement("div");
      dayNumber.classList.add("day-number");
      dayNumber.textContent = day;
      dayCell.appendChild(dayNumber);

      const dateKey = `${year}-${String(month + 1).padStart(2, "0")}-${String(
        day
      ).padStart(2, "0")}`;
      const slotsForDay = slotsData[dateKey] || [];

      if (slotsForDay.length > 0) {
        slotsForDay.forEach((slot) => {
          const div_wrapper = document.createElement("div");
          div_wrapper.className = "slots-wrapper";

          const table = document.createElement("table");
          table.classList.add("slots-table");

          const tr = document.createElement("tr");
          tr.style.position = "relative";

          const startTime = slot.time;
          const startDate = new Date(`${dateKey}T${startTime}`);
          const endDate = new Date(
            startDate.getTime() + slot.length_min * 60000
          );
          const endTimeStr = endDate.toTimeString().slice(0, 5);

          // Time cell
          const timeTd = document.createElement("td");
          timeTd.textContent = `${startTime} - ${endTimeStr}`;
          timeTd.classList.add("hover-target", "time-cell");
          tr.appendChild(timeTd);

          // Spots cell
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
            btn.style.position = "absolute";
            btn.style.top = "0";
            btn.style.left = "0";
            btn.style.width = "100%";
            btn.style.height = "100%";
            btn.style.backgroundColor = "#4caf50";
            btn.style.color = "white";
            btn.style.border = "none";
            btn.style.borderRadius = "4px";
            btn.style.fontSize = "14px";
            btn.style.display = "flex";
            btn.style.justifyContent = "center";
            btn.style.alignItems = "center";
            btn.style.opacity = "0";
            btn.style.pointerEvents = "none";
            btn.style.transition = "opacity 0.2s ease-in-out";
            btn.style.zIndex = "10";
            btn.style.cursor = "pointer";
            


            btn.addEventListener("click", (e) => {
              e.stopPropagation();
              triggerBooking(slot, btn, startTime, endTimeStr, dateKey)
            });

            tr.appendChild(btn);

             tr.addEventListener("click", () => {
                triggerBooking(slot, btn, startTime, endTimeStr, dateKey);
              });

            tr.addEventListener("mouseenter", () => {
              if (!btn.disabled) {
                btn.style.opacity = "1";
                btn.style.pointerEvents = "auto";
              }
            });
            tr.addEventListener("mouseleave", () => {
              if (!btn.disabled) {
                btn.style.opacity = "0";
                btn.style.pointerEvents = "none";
              }
            });
          }
          table.appendChild(tr);
          div_wrapper.appendChild(table);
          dayCell.appendChild(div_wrapper);
        });
      }
      calendarBody.appendChild(dayCell);
    }
  }

        serviceSelect.addEventListener('change', updateCalendar);
        modeSelect.addEventListener('change', modeSelectStyling);

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

    .hiddenService {
        display: none !important;
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


    .booked-wave {
    animation: wave-text 1s ease-in-out forwards;
    pointer-events: none; /* disables clicks */
    cursor: default;
    color: black !important; /* dark yellow */
    background-color: yellow !important;
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


add_action('rest_api_init', function () {
    register_rest_route('pcp/v1', '/admin/delete_time_slot', [
        'methods' => 'POST',
        'callback' => 'admin_delete_time_slot',
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

function admin_delete_time_slot(WP_REST_Request $request) {
    global $wpdb;

    $availability_id = intval($request->get_param('availability_id'));

    if (!$availability_id || $availability_id == -1) {
        return new WP_REST_Response([
            'success' => false,
            'message' => 'Missing spot ID',
        ], 400);
    }

    $time_slot_to_delete = $wpdb->get_row($wpdb->prepare(
        "SELECT available_date, time_slot, time_slot_length_min, service_id 
         FROM {$wpdb->prefix}availability
         WHERE availability_id=%d", $availability_id       
    ));

    $service_name = $wpdb->get_var($wpdb->prepare(
        "SELECT service_name
        FROM {$wpdb->prefix}services
        WHERE service_id = %d",
        $time_slot_to_delete->service_id
    ));

    if (!$time_slot_to_delete) {
    return new WP_REST_Response([
        'success' => false,
        'message' => 'Time slot not found',
    ], 404);
    }
    $wpdb->query('START TRANSACTION');

    $record_info = $wpdb->get_row(
        $wpdb->prepare(
            "SELECT
                s.service_name,
                s.service_cost_main AS price_main,
                s.service_cost_provider AS price_provider,
                p.provider_name,
                p.sales_email AS provider_email,
                a.available_date AS booking_for_date,
                a.time_slot AS booking_time_slot,
                a.time_slot_length_min AS booking_time_slot_length_min
            FROM {$wpdb->prefix}availability a
            JOIN {$wpdb->prefix}services s ON s.service_id = a.service_id
            JOIN {$wpdb->prefix}provider_sites p ON p.provider_id = s.provider_id
            WHERE a.availability_id = %d",
            $availability_id
        )
    );

    $availability_ids_to_record = $wpdb->get_results(
        $wpdb->prepare(
            "SELECT availability_id
            FROM {$wpdb->prefix}availability 
            WHERE available_date = %s
            AND time_slot = %s
            AND time_slot_length_min = %d
            AND service_id = %d
            AND status = %s",
            $time_slot_to_delete->available_date,
            $time_slot_to_delete->time_slot,
            $time_slot_to_delete->time_slot_length_min,
            $time_slot_to_delete->service_id,
            'b'
        )
    );
    $total_inserted = 0;
    $inserted_records = [];
    foreach($availability_ids_to_record as $availability)
    {
    $id = $availability->availability_id;
    $booked_info = $wpdb->get_row(
        $wpdb->prepare(
            "SELECT booked_by_main, customer_name, customer_email, customer_number
            FROM {$wpdb->prefix}bookings
            WHERE availability_id = %d",
            $id
        )
    );

    $inserted = $wpdb->insert(
            "{$wpdb->prefix}customer_refunds",
            [
                'provider_name'                 => $record_info->provider_name,
                'service_name'                  => $record_info->service_name,
                'booking_for_date'              => $record_info->booking_for_date,
                'booking_time_slot'             => $record_info->booking_time_slot,
                'booking_time_slot_length_min'  => $record_info->booking_time_slot_length_min,
                'price_main'                    => (float) $record_info->price_main,
                'price_provider'                => (float) $record_info->price_provider,
                'booked_by_main'                => $booked_info->booked_by_main ? 1 : 0,
                'customer_name'                 => $booked_info->customer_name,
                'customer_email'                => $booked_info->customer_email,
                'customer_number'               => $booked_info->customer_number,
            ],
            [
                '%s','%s','%s','%s','%d','%f','%f','%d','%s','%s','%s'
            ]
        );

        if ($inserted !== false) {
            $total_inserted++;
            $inserted_records[] = (object) array_merge(
                (array) $record_info,
                (array) $booked_info
            );
        }
    }

    if ($total_inserted != count($availability_ids_to_record)) {
        $wpdb->query('ROLLBACK');
        return new WP_REST_Response([
            'success' => false,
            'message' => 'Refund insert failed',
        ], 500);
    }
    

    $updated = $wpdb->query(
        $wpdb->prepare(
            "UPDATE {$wpdb->prefix}sale_records
            SET refund_process_init = 1
            WHERE DATE(booking_for_date) = %s
            AND booking_time_slot = %s
            AND booking_time_slot_length_min = %d
            AND service_name = %s
            AND refund_process_init = 0",
            $time_slot_to_delete->available_date,
            $time_slot_to_delete->time_slot,
            $time_slot_to_delete->time_slot_length_min,
            $service_name
        )
    );

    if ($updated === false) {
        $wpdb->query('ROLLBACK');
        return new WP_REST_Response(['success' => false, 'message' => 'Sale update failed'], 500);
    }

    $deleted = $wpdb->delete(
        $wpdb->prefix . 'availability',
        [
            'available_date' => $time_slot_to_delete->available_date,
            'time_slot' => $time_slot_to_delete->time_slot,
            'time_slot_length_min' => $time_slot_to_delete->time_slot_length_min,
            'service_id' => $time_slot_to_delete->service_id,
        ],
        [ '%s', '%s', '%d', '%d' ]
    );

    if ($deleted === false) {
        $wpdb->query('ROLLBACK');
        return new WP_REST_Response(['success' => false, 'message' => 'Delete failed'], 500);
    }

    $wpdb->query('COMMIT');

    foreach ($inserted_records as $record) {
        send_refund_email($record);
    }

    if ($deleted === 0) {
        return new WP_REST_Response([
            'success' => false,
            'message' => 'No matching time slots found to delete',
        ], 404);
    }

    return rest_ensure_response([
            'success' => true,
            'message' => 'Time slot deleted!',
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
        update_providers_availability_spots($session_id);
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
        update_sales_record($reference);
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

    $paystack_info_row = $wpdb->get_row("
        SELECT paystack_live, paystack_api_key_test, paystack_api_key_secret
        FROM {$wpdb->prefix}paystack_info
        WHERE id = 1
    ");

    if ($paystack_info_row) {
        $live_mode = (int) $paystack_info_row->paystack_live;
        if ($live_mode === 0) {
            $paystack_secret = $paystack_info_row->paystack_api_key_test;
        } else {
            $paystack_secret = $paystack_info_row->paystack_api_key_secret;
        }
    } else {
        $paystack_secret = '';
    }    
    

    if (empty($paystack_secret)) {
        return new WP_REST_Response(['error' => 'No paystack secret key'], 500);
    }

    $results = $wpdb->get_results($wpdb->prepare("
        SELECT 
            a.availability_id,
            s.service_cost_main,
            s.service_cost_provider,
            p.paystack_subaccount,
            p.paystack_subaccount_test
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
        if($live_mode === 0)
        {
            $paystack_subaccount = $row['paystack_subaccount_test'];
        }
        else{
            $paystack_subaccount = $row['paystack_subaccount'];
        }
        
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

    $split = build_split($provider_data, $total_cost, $live_mode);

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


function build_split($provider_data, $total_cost, $live_mode){
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

    
        if($live_mode === 0)
        {
            $subaccounts[] = [
                'subaccount' => 'ACCT_nwd4b21sk8j3xby', //MY_PACKSTACK CODE HARDCODE REPLACE ADD
                'share' => $operational_cost //MY SHARE 
            ];
        }
        else{
            $subaccounts[] = [
                'subaccount' => 'ACCT_0jrt44sizueubsp', //MY_PACKSTACK CODE HARDCODE REPLACE ADD
                'share' => $operational_cost //MY SHARE 
            ];
        }    

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

        update_sales_record($reference);

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

    $session_id = bin2hex(random_bytes(4)) . uniqid('', true);

    return rest_ensure_response([
        'success' => true,
        'data' => ['session_id' => $session_id]
    ]);

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
        paystack_api_key_test VARCHAR(255),
        paystack_live BOOLEAN NOT NULL DEFAULT 0,
        PRIMARY KEY (id)
    ) $charset_collate;";

    $provider_sites_table = $wpdb->prefix . 'provider_sites';
    $sql1 = "CREATE TABLE IF NOT EXISTS $provider_sites_table (
        provider_id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
        provider_name VARCHAR(200) NOT NULL,
        api_key VARCHAR(255) NOT NULL,
        hmac_secret VARCHAR(255),
        paystack_subaccount VARCHAR(255),
        paystack_subaccount_test VARCHAR(255),
        base_api_url TEXT DEFAULT NULL,
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
        service_description VARCHAR(255),
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

    $sale_records = $wpdb->prefix . 'sale_records';
    $sql5 = "CREATE TABLE IF NOT EXISTS $sale_records (
        sale_id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
        provider_name VARCHAR(100) NOT NULL,
        service_name VARCHAR(100) NOT NULL,
        booking_for_date DATETIME NOT NULL,
        booking_time_slot TIME NOT NULL,
        booking_time_slot_length_min INT NOT NULL,
        price_main DECIMAL(10,2) NOT NULL,
        price_provider DECIMAL(10,2) NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        refund_process_init BOOL DEFAULT 0,
        PRIMARY KEY (sale_id)
    ) $charset_collate;";        

    $customer_refunds = $wpdb->prefix . 'customer_refunds';
    $sql6 = "CREATE TABLE IF NOT EXISTS $customer_refunds (
        refund_id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
        provider_name VARCHAR(100) NOT NULL,
        service_name VARCHAR(100) NOT NULL,
        booking_for_date DATETIME NOT NULL,
        booking_time_slot TIME NOT NULL,
        booking_time_slot_length_min INT NOT NULL,
        price_main DECIMAL(10,2) NOT NULL,
        price_provider DECIMAL(10,2) NOT NULL,
        status ENUM('t','p','d') DEFAULT 't',
        booked_by_main  BOOLEAN NOT NULL,
        customer_name VARCHAR(100),
        customer_email VARCHAR(100),
        customer_number VARCHAR(100),
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (refund_id)
    ) $charset_collate;";        

    require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
    dbDelta($sql0);
    dbDelta($sql1);
    dbDelta($sql2);
    dbDelta($sql3);
    dbDelta($sql4);
    dbDelta($sql5);
    dbDelta($sql6);
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
    $table = $wpdb->prefix . 'provider_sites';

    if ($wpdb->get_var("SHOW TABLES LIKE '$table'") != $table) {
        create_tables();
    }

    if (isset($_POST['add_provider'])) {
        $provider_name = sanitize_text_field($_POST['provider_name']);
        $online= isset($_POST['online']) ? 1 : 0;        
        $base_api_url = $online ? esc_url_raw($_POST['base_api_url']) : null;
        $paystack_subaccount = sanitize_text_field($_POST['paystack_subaccount']);

        $paystack_subaccount_test = sanitize_text_field($_POST['paystack_subaccount_test']);

        $sales_email = sanitize_email($_POST['sales_email']);
        $api_key = create_api_key();
        $hmac_secret = create_hmac_secret();

        $wpdb->insert($table, [
            'provider_name' => $provider_name,
            'api_key' => $api_key,
            'hmac_secret' => $hmac_secret,
            'base_api_url' => $base_api_url,
            'paystack_subaccount' => $paystack_subaccount,
            'paystack_subaccount_test' => $paystack_subaccount_test,
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
            <tr><th><label for="online">Online</label></th><td><input type="checkbox" name="online" id="online" value="0" /></td></tr>
            <tr><th><label for="base_api_url">Base API url</label></th><td><input name="base_api_url" id="base_api_url" /></td></tr>
            <tr><th><label for="paystack_subaccount">Paystack Subaccount (Live)</label></th><td><input name="paystack_subaccount" required /></td></tr>
            <tr><th><label for="paystack_subaccount_test">Paystack Subaccount (Test)</label></th><td><input name="paystack_subaccount_test" required /></td></tr>
            <tr><th><label for="sales_email">Sales Email</label></th><td><input name="sales_email" type="email" required /></td></tr>
        </table>
        <input type="submit" name="add_provider" class="button-primary" value="Add Provider" />
    </form>';

    if (!empty($providers)) {
        echo '<h2>Registered Providers</h2>';
        echo '<form method="post">';
        echo '<table class="widefat">';
        echo '<thead><tr><th>ID</th><th>Name</th><th>Base API url</th><th>Sales Email</th><th>Paystack Subaccount (Live)</th><th>Paystack Subaccount (Test)</th><th>API Key</th><th>HMAC Secret</th><th>Active</th><th>Actions</th></tr></thead><tbody>';

        foreach ($providers as $p) {
            $api_key_id = 'api_key_' . $p->provider_id;
            $hmac_id = 'hmac_secret_' . $p->provider_id;

            echo '<tr>';
            echo '<td>' . esc_html($p->provider_id) . '</td>';
            echo '<td>' . esc_html($p->provider_name) . '</td>';
            echo '<td>' . esc_url($p->base_api_url) . '</td>';
            echo '<td>' . esc_html($p->sales_email) . '</td>';
            echo '<td>' . esc_html($p->paystack_subaccount) . '</td>';
            echo '<td>' . esc_html($p->paystack_subaccount_test) . '</td>';

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
      document.addEventListener("DOMContentLoaded", () => {
        const onlineCheckbox = document.getElementById("online");
        const baseUrlInput = document.getElementById("base_api_url");

        function toggleBaseUrl() {
            if (onlineCheckbox.checked) {
            baseUrlInput.disabled = false;
            } else {
            baseUrlInput.disabled = true;
            baseUrlInput.value = ""; // clear when unchecked
            }
        }

        onlineCheckbox.addEventListener("change", toggleBaseUrl);
        toggleBaseUrl(); // run on page load
        });
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
        $service_description = sanitize_text_field($_POST['service_description']);
        $service_description = substr($service_description, 0, 255);

        // Round up to nearest 1 after adding 10%
        $service_cost_main = ceil($service_cost_provider * 1.1);

        $valid_cost = $service_cost_provider >= 10;
        $valid_maxSpots = $max_spots > 0;

        
        $existing = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM $services_table WHERE service_name = %s AND provider_id = %d",
            $service_name, $provider_id
        ));

        if($valid_cost && $valid_maxSpots)
            {
                if ($existing > 0) {
                    echo '<div class="notice notice-warning"><p>Service already exists.</p></div>';
                } else {
                    $wpdb->insert($services_table, [
                        'provider_id'           => $provider_id,
                        'service_name'          => $service_name,
                        'service_cost_provider' => $service_cost_provider,
                        'service_cost_main'     => $service_cost_main,
                        'max_spots'             => $max_spots,
                        'service_description'   => $service_description
                    ]);

                    echo '<div class="updated"><p>Service added.</p></div>';
                }
            }
         else
            {
                echo '<div class="notice notice-warning"><p>Invalid cost or max spots</p></div>';
            }
    }

    $availability_table = $wpdb->prefix . 'availability';

    // Delete service
    if (isset($_POST['delete_service_id'])) {
        $delete_id = intval($_POST['delete_service_id']);
        
        $has_bookings = $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM $availability_table WHERE service_id = %d AND status IN ('p','b')",
            $delete_id
        ) );

        if ($has_bookings) {
            echo '<div class="error"><p>Cannot delete service: there are pending or booked slots.</p></div>';
        } else {
            // Safe to delete
            $deleted = $wpdb->delete($services_table, ['service_id' => $delete_id]);
            if ($deleted) {
                echo '<div class="updated"><p>Service deleted.</p></div>';
            } else {
                echo '<div class="error"><p>Service deletion failed.</p></div>';
            }
        }
    }

    //Edit cost and service description
    if (isset($_POST['edit_form_update'])) {
        $edit_id = intval($_POST['edit_service_id']);
        $new_cost_provider = floatval($_POST['new_service_cost_provider']);
        $new_cost_main = ceil($new_cost_provider * 1.1);
        $new_service_description = strval($_POST['new_service_description']);

        $exists = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM $services_table WHERE service_id = %d AND provider_id = %d",
            $edit_id, $provider_id
        ));

        $valid_cost = $new_cost_provider > 10;

        if ($exists && $valid_cost) {
            $wpdb->update(
                $services_table,
                [
                    'service_cost_provider' => $new_cost_provider,
                    'service_cost_main'     => $new_cost_main,
                    'service_description'   => $new_service_description
                ],
                ['service_id' => $edit_id]
            );
            echo '<div class="updated"><p>Service description and cost updated.</p></div>';
        }
    }

    

    // Add form
    echo '<form method="post" style="margin-bottom: 1em;">';
    echo '<input type="text" name="service_name" placeholder="New Service Name" required> ';
    echo '<input type="number" name="service_cost" placeholder="Cost (Provider)" step="0.01" min="10" required> ';
    echo '<input type="number" name="max_spots" placeholder="Max Spots" min="1" required> <br><br>';
    echo '<textarea name="service_description" 
        placeholder="Service Description (255 characters max)" 
        maxlength="255" 
        rows="4" 
        cols="50" 
        required></textarea> <br>';
    echo '<input type="submit" name="add_service_submit" class="button button-primary" value="Add Service">';
    echo '</form>';

    // Display services
    $services = $wpdb->get_results($wpdb->prepare(
        "SELECT * FROM $services_table WHERE provider_id = %d",
        $provider_id
    ));

    if ($services) {
        echo '<table class="widefat striped">';
        echo '<thead><tr><th>Service Name</th><th>Provider Cost</th><th>Main Cost</th><th>Max Spots</th><th>Service Description</th><th>Actions</th></tr></thead><tbody>';

        foreach ($services as $service) {
            $service_id = intval($service->service_id);
            echo '<tr>';
            echo '<td>' . esc_html($service->service_name) . '</td>';
            echo '<td>R' . esc_html(number_format($service->service_cost_provider, 2)) . '</td>';
            echo '<td>R' . esc_html(number_format($service->service_cost_main, 2)) . '</td>';
            echo '<td>' . esc_html($service->max_spots) . '</td>';
            echo '<td>' . esc_html($service->service_description) . '</td>';
            echo '<td>';            

            echo '
            <button type="button" class="button" onclick="toggleEditForm(' . $service_id . ')">Edit</button>

            <div id="edit_form_' . $service_id . '" style="display:none; margin-top:10px;">
                <form method="post" onsubmit="return confirm(\'Save changes?\');">
                    <input type="hidden" name="edit_service_id" value="' . $service_id . '">

                    <textarea name="new_service_description" 
                    maxlength="255" 
                    rows="4" 
                    cols="50" 
                    required>' . esc_html($service->service_description) . '</textarea> <br>

                    <input type="number" value="'.esc_html(number_format($service->service_cost_provider, 2)) .'" name="new_service_cost_provider" step="0.01" min="10" required><br><br>

                    <input type="submit" name="edit_form_update" class="button button-primary" value="Save">
                    <button type="button" class="button" onclick="toggleEditForm(' . $service_id . ')">Cancel</button>
                </form>
            </div>
            ';           
            echo '<form method="post" style="display:inline-block;margin-right:10px;" onsubmit="return confirm(\'Are you sure you want to delete this service?\');">';
            echo '<input type="hidden" name="delete_service_id" value="' . intval($service->service_id) . '">';
            echo '<input type="submit" class="button button-secondary" value="Delete">';
            echo '</form>';

            echo '</td>';
            
            
            echo '</tr>';
        }

        echo '</tbody></table>';
    } else {
        echo '<p><em>No services found for this provider.</em></p>';
    }

    echo '
    <script>
    function toggleEditForm(id) {
        document.querySelectorAll("[id^=\'edit_form_\']").forEach(function(form) {
            if (form.id !== "edit_form_" + id) {
                form.style.display = "none";
            }
        });

        const target = document.getElementById("edit_form_" + id);
        if (!target) return;

        target.style.display = (target.style.display === "block") ? "none" : "block";
    }
    </script>
    ';

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

    echo '<form method="post" onsubmit="return confirm(\'Are you sure you want to add these time slots?\');" style="margin-bottom: 1em;">';

    echo '<label for="service-select" style="margin-top:1em; margin-bottom:1em;">Select Service:</label>';

    echo '<select id="service-select" name="service_id">';
    $first = true; 
    foreach ($services as $service) {
        echo '<option value="' . esc_attr($service->service_id) . '"';
        if ($first) {
            echo ' selected';
            $first = false;
        }
        echo '>' . esc_html($service->service_name) . '</option>';
    }
    echo '</select>';

    $selected_service_id = isset($_POST['service_id']) ? intval($_POST['service_id']) : $services[0]->service_id;


    $service = null;
    foreach ($services as $s) {
        if ($s->service_id == $selected_service_id) {
            $service = $s;
            break;
        }
    }

    if (!$service) {
        $service = $services[0]; 
    }

    $service_id = $service->service_id;
    $service_name = $service->service_name;
    $max_spots = $service->max_spots;
        
        $undo_data = '';

        if (isset($_POST['add_time_slot'])) {
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

         echo '<div style="display: flex; gap: 2em; align-items: center; margin-bottom: 1.0em; margin-top: 1.0em;">';
    echo '<div><label style="font-weight:bold;">From Time<br><input type="time" name="from_time" required></label></div>';
    echo '<div><label style="font-weight:bold;">To Time<br><input type="time" name="to_time" required></label></div>';
    echo '</div>';

    echo '<div style="display: flex; gap: 2em; align-items: center; margin-bottom: 1.0em;  margin-top: 1.0em;">';
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

    echo '<div style="display: flex; gap: 10px; align-items: center;">';


    echo '<input type="submit" class="button button-primary" name="add_time_slot" value="Add Time Slot">';

    

    echo '</form>';
    if (!empty($undo_data)) {
            echo '<form method="post" style="margin:0; display: inline-block;">';
            echo '<input type="hidden" name="undo_slots" value="' . esc_attr($undo_data) . '">';
            echo '<input type="submit" name="undo_action" class="button button-secondary" value="Undo">';
            echo '</form>';
        }
    echo '</div>';


        


    

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
    $booked_by_filter = isset($_POST['booked_by']) ? intval($_POST['booked_by']) : 0;

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


    if ($booked_by_filter === 1) {
        $conditions[] = "b.booked_by_main = 1";
    } elseif ($booked_by_filter === 2) {
        $conditions[] = "b.booked_by_main = 0";
    }

    $where_sql = "WHERE " . implode(" AND ", $conditions);

    $query = "
        SELECT 
            a.available_date AS booking_date,
            a.time_slot,            
            s.service_name,
            b.booked_by_main,
            b.customer_name,
            b.customer_email,
            b.customer_number,
            a.session_id
        FROM {$bookings_table} b
        INNER JOIN {$availability_table} a ON b.availability_id = a.availability_id
        INNER JOIN {$services_table} s ON a.service_id = s.service_id
        $where_sql
        ORDER BY a.available_date ASC, a.time_slot ASC, s.service_name ASC
    ";

    $results = $wpdb->get_results($wpdb->prepare($query, $params));


    echo '<div class="wrap"><h1>Bookings</h1>';


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


    echo '<label>Booked By: <select name="booked_by">';
    echo '<option value="0"' . selected($booked_by_filter, 0, false) . '>All</option>';
    echo '<option value="1"' . selected($booked_by_filter, 1, false) . '>Website</option>';
    echo '<option value="2"' . selected($booked_by_filter, 2, false) . '>Private</option>';
    echo '</select></label> ';

    echo '<input type="submit" class="button button-primary" value="Filter">';
    echo '</form>';

    if ($results) {
        echo '<table class="widefat striped">';
        echo '<thead><tr>';
        echo '<th>Date</th>';
        echo '<th>Time Start</th>';
        echo '<th>Service</th>';
        echo '<th>Booked By Website</th>';
        echo '<th>Customer Name</th>';
        echo '<th>Customer Email</th>';
        echo '<th>Phone number</th>';
        echo '<th>Reference</th>';
        echo '</tr></thead><tbody>';

        foreach ($results as $row) {
            echo '<tr>';
            echo '<td>' . esc_html($row->booking_date) . '</td>';
            echo '<td>' . esc_html(date('H:i', strtotime($row->time_slot))) . '</td>';
            echo '<td>' . esc_html($row->service_name) . '</td>';
            echo '<td>' . ($row->booked_by_main ? 'Yes' : 'No') . '</td>';
            echo '<td>' . esc_html($row->customer_name) . '</td>';
            echo '<td>' . esc_html($row->customer_email) . '</td>';
            echo '<td>' . esc_html($row->customer_number) . '</td>';
            echo '<td>' . esc_html($row->session_id) . '</td>';
            echo '</tr>';
        }

        echo '</tbody></table>';
    } else {
        echo '<p>No bookings found for the selected filters.</p>';
    }

    echo '</div>';
}

add_action('admin_menu', 'provider_payments_dashboard_menu');

function provider_payments_dashboard_menu() {
        if (current_user_can('provider')) {
            add_menu_page(
                'Payments',
                'Payments',
                'read',
                'provider-payments-dashboard',
                'provider_payments_dashboard_page',
                'dashicons-cart',
                6
            );
        }
}

function provider_payments_dashboard_page() {
    global $wpdb;
    $sales_table = $wpdb->prefix . 'sale_records';
    $current_user = wp_get_current_user();
    $username = $current_user->user_login;

    $earnings_by_service = $wpdb->get_results($wpdb->prepare(
        "SELECT service_name, SUM(price_provider) AS total_provider_cost
         FROM {$sales_table} 
         WHERE provider_name = %s
         GROUP BY service_name",
        $username
    ));

    $pie_labels = wp_json_encode(array_map(fn($s) => $s->service_name, $earnings_by_service));
    $pie_values = wp_json_encode(array_map(fn($s) => (float)$s->total_provider_cost, $earnings_by_service));
    $rest_url = esc_url_raw(rest_url('pcp/v1'));
    $rest_nonce = wp_create_nonce('wp_rest');
    ?>

    <style>
    .chart-container {
        max-width: 700px;
        margin: 20px auto;
        display: flex;
        justify-content: center;
        flex-direction: column;
        align-items: center;
    }

    .chart-inner {
        width: 100%;
        max-width: 600px;
        text-align: center;
    }

    .chart-inner canvas {
        display: block;
        margin: 0 auto;
        width: 100% !important; /* full width */
        height: 400px; /* default height for desktop/tablet */
    }

    @media (max-width: 768px) {
        .chart-inner canvas {
            height: 350px; /* smaller on tablets */
        }
    }

    @media (max-width: 480px) {
        .chart-inner canvas {
            height: 500px; /* taller on mobile so it's readable */
        }
    }
    </style>

    <!-- Pie Chart -->
    <div class="chart-container">
        <div class="chart-inner">
            <label for="chart-select-pie">Chart Type:</label>
            <select id="chart-select-pie">
                <option value="pie">Pie</option>
                <option value="bar">Bar</option>
            </select>
            <canvas id="salesPieChart"></canvas>
        </div>
    </div>

    <!-- Line/Bar Chart -->
    <div class="chart-container">
        <div class="chart-inner">
            <label for="chart-select-line">Chart Type:</label>
            <select id="chart-select-line">
                <option value="line">Line</option>
                <option value="bar">Bar</option>
            </select><br>

            <label for="service-select">Service:</label>
            <select id="service-select">
                <option value="all">All Services</option>
                <?php foreach ($earnings_by_service as $s): ?>
                    <option value="<?= esc_attr($s->service_name) ?>"><?= esc_html($s->service_name) ?></option>
                <?php endforeach; ?>
            </select><br>

            <label for="from-date">From:</label>
            <input type="date" id="from-date"><br>

            <label for="to-date">To:</label>
            <input type="date" id="to-date"><br>

            <button id="filter-line-chart">Filter</button><br>

            <canvas id="salesLineChart"></canvas>
        </div>
    </div>

    <!-- Time-slot Chart -->
    <div class="chart-container">
        <div class="chart-inner">
            <label for="chart-select-time-slot">Chart Type:</label>
            <select id="chart-select-time-slot">
                <option value="bar">Bar</option>
                <option value="pie">Pie</option>
            </select><br>

            <label for="service-select-time-slot">Service:</label>
            <select id="service-select-time-slot">
                <option value="all">All Services</option>
                <?php foreach ($earnings_by_service as $s): ?>
                    <option value="<?= esc_attr($s->service_name) ?>"><?= esc_html($s->service_name) ?></option>
                <?php endforeach; ?>
            </select><br>

            <canvas id="timeSlotChart"></canvas>
        </div>
    </div>



    <script>
    document.addEventListener('DOMContentLoaded', function () {
        const pieCtx = document.getElementById("salesPieChart");
        const lineCtx = document.getElementById("salesLineChart");
        const timeSlotCtx = document.getElementById("timeSlotChart");

        let pieChart;

        function fetchPieChart(chartType)
        {
            if(pieChart) pieChart.destroy();

            pieChart = new Chart(pieCtx, {
                type: chartType,
                data: {
                    labels: <?php echo $pie_labels; ?>,
                    datasets: [{
                        label: 'Provider Earnings',
                        data: <?php echo $pie_values; ?>,
                        backgroundColor: [
                            'rgba(255, 99, 132, 0.6)',
                            'rgba(54, 162, 235, 0.6)',
                            'rgba(255, 206, 86, 0.6)',
                            'rgba(75, 192, 192, 0.6)',
                            'rgba(153, 102, 255, 0.6)',
                            'rgba(255, 159, 64, 0.6)'
                        ],
                        borderWidth: 1
                    }]
                },
                options: {
                    responsive: true,
                    plugins: {
                        legend: { 
                            display: chartType !== 'bar',
                            position: 'right' 
                        },
                        title: { display: true, text: 'Earnings by Service', font: { size: 24, weight: 'bold' } }
                    }
                }
            });
        }       

        let lineChart;

        function fetchLineChartData(service, from, to, chartType) {
            fetch("<?php echo $rest_url . '/provider/sales_history'; ?>", {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-WP-Nonce': '<?php echo $rest_nonce; ?>'
                },
                body: JSON.stringify({ service, from, to })
            })
            .then(res => res.json())
            .then(data => {
                const dateMap = {};
                data.forEach(item => {
                    if(!dateMap[item.created_at]) dateMap[item.created_at] = 0;
                    dateMap[item.created_at] += item.total_provider_cost;
                });

                const dates = Object.keys(dateMap).sort();
                const values = dates.map(d => dateMap[d]);

                if(lineChart) lineChart.destroy();

                lineChart = new Chart(lineCtx, {
                    type: chartType,
                    data: {
                        labels: dates,
                        datasets: [{
                            label: 'Earnings Over Time',
                            data: values,
                            borderColor: 'rgba(0, 100, 167, 1)',
                            backgroundColor: 'rgba(55, 174, 253, 0.7)',
                            fill: true,
                            tension: 0.5
                        }]
                    },
                    options: {
                        responsive: true,
                        plugins: {
                            legend: { position: 'top' },
                            title: { display: true, text: 'Earnings Over Time', font: { size: 20, weight: 'bold' } }
                        },
                        scales: {
                            x: { title: { display: true, text: 'Date' } },
                            y: { title: { display: true, text: 'Earnings' } }
                        }
                    }
                });
            });
        }
        
        let timeSlotChart;
        function fetchTimeSlotChart(service, chartType){
            fetch("<?php echo $rest_url . '/provider/by_time_slot'; ?>", {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-WP-Nonce': '<?php echo $rest_nonce; ?>'
                },
                body: JSON.stringify({ service })
            })
            .then(res => res.json())
            .then(data => {
                const slotMap = {};
                data.forEach(item => {
                    const slot = item.time_range;  // no date, only time range
                    if (!slotMap[slot]) slotMap[slot] = 0;
                    slotMap[slot] += item.total_provider_cost;
                });

                const slots = Object.keys(slotMap);
                const values = slots.map(s => slotMap[s]);

                            const colors = [
                                'rgba(255, 99, 132, 0.6)',
                                'rgba(54, 162, 235, 0.6)',
                                'rgba(255, 206, 86, 0.6)',
                                'rgba(75, 192, 192, 0.6)',
                                'rgba(153, 102, 255, 0.6)',
                                'rgba(255, 159, 64, 0.6)',
                                'rgba(201, 203, 207, 0.6)',
                                'rgba(100, 149, 237, 0.6)',
                                'rgba(60, 179, 113, 0.6)',
                            ];

                            const datasetColors = slots.map((_, i) => colors[i % colors.length]);

                if (timeSlotChart) timeSlotChart.destroy();

                timeSlotChart = new Chart(timeSlotCtx, {
                    type: chartType,
                    data: {
                        labels: slots,
                        datasets: [{
                            label: 'Earnings By Time-slot',
                            data: values,
                            backgroundColor: datasetColors,
                            borderColor: datasetColors.map(c => c.replace('0.6', '1')),
                            fill: true,
                            borderWidth: 1
                        }]
                    },
                    options: {
                        responsive: true,
                        plugins: {
                            legend: { position: 'top' },
                            title: { display: true, text: 'Earnings By Time-slot', font: { size: 20, weight: 'bold' } }
                        },
                        scales: {
                            x: { title: { display: true, text: 'Time-slot' } },
                            y: { title: { display: true, text: 'Earnings' } }
                        }
                    }
                });
            });

        }

        fetchPieChart('pie');
        fetchLineChartData('all', null, null, 'line');
        fetchTimeSlotChart('all', 'bar')

        document.getElementById('filter-line-chart').addEventListener('click', function() {
            const service = document.getElementById('service-select').value;
            const from = document.getElementById('from-date').value || null;
            const to = document.getElementById('to-date').value || null;
            const chart = document.getElementById('chart-select-line').value;
            fetchLineChartData(service, from, to, chart);
        });

        document.getElementById('chart-select-line').addEventListener('change', function() {
            const service = document.getElementById('service-select').value;
            const from = document.getElementById('from-date').value || null;
            const to = document.getElementById('to-date').value || null;
            const chart = document.getElementById('chart-select-line').value;
            fetchLineChartData(service, from, to, chart);
        });

        document.getElementById('chart-select-pie').addEventListener("change", () => {
            const chart = document.getElementById('chart-select-pie').value;
            fetchPieChart(chart);
        });

        document.getElementById('chart-select-time-slot').addEventListener("change", () => {
            const service = document.getElementById('service-select-time-slot').value;
            const chart = document.getElementById('chart-select-time-slot').value;
            fetchTimeSlotChart(service, chart)
        });

        document.getElementById('service-select-time-slot').addEventListener("change", () => {
            const service = document.getElementById('service-select-time-slot').value;
            const chart = document.getElementById('chart-select-time-slot').value;
            fetchTimeSlotChart(service, chart)
        });


    });
    </script>

    <?php
}


//Refund page
add_action('admin_menu', 'provider_refund_dashboard_menu');

function provider_refund_dashboard_menu() {
        if (current_user_can('provider')) {
            add_menu_page(
                'Refunds',
                'Refunds',
                'read',
                'provider-refunds-dashboard',
                'provider_refunds_dashboard_page',
                'dashicons-pressthis',
                5
            );
        }
}

function provider_refunds_dashboard_page() {
    global $wpdb;

    $customer_refunds = $wpdb->prefix . 'customer_refunds';
    $current_user = wp_get_current_user();
    $username = $current_user->user_login;

    // Fetch all refunds for this provider
    $refunds = $wpdb->get_results(
        $wpdb->prepare(
            "SELECT * FROM {$customer_refunds} 
             WHERE provider_name = %s
             ORDER BY booking_for_date DESC, booking_time_slot ASC",
            $username
        )
    );

    // Split by status
    $statuses = [
        't' => [],
        'p' => [],
        'd' => [],
    ];

    foreach ($refunds as $refund) {
        $status = $refund->status;
        if (isset($statuses[$status])) {
            $statuses[$status][] = $refund;
        }
    }

    // Table styling
    echo '<style>
        .refunds-table { width: 90%; border-collapse: collapse; margin-bottom: 2rem; }
        .refunds-table th, .refunds-table td { border: 1px solid #ccc; padding: 8px; text-align: left; }
        .refunds-table th { background-color: #f5f5f5; }
        .refunds-section { margin-bottom: 3rem; }
        .status-btn { padding: 4px 8px; cursor: pointer; background-color: #0073aa; color: #fff; border: none; border-radius: 3px; }
        .status-btn:hover { background-color: #005177; }
    </style>';

    // Include REST API JS call
    ?>
    <script>
    async function changeRefundStatus(refundId, currentStatus) {
        const nextStatus = currentStatus === 't' ? 'p' : 'd';
        const nonce = '<?php echo wp_create_nonce("wp_rest"); ?>';

        const response = await fetch('<?php echo esc_url(rest_url("pcp/v1/update_refund_status")); ?>', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-WP-Nonce': nonce
            },
            body: JSON.stringify({
                refund_id: refundId,
                new_status: nextStatus
            })
        });

        const data = await response.json();
        if(data.success) {
            location.reload();
        } else {
            alert('Failed to update status: ' + (data.message || 'Unknown error'));
        }
    }
    </script>
    <?php

    echo '<div class="refunds-dashboard">';
    echo '<h1>Refunds</h1>';

    $status_labels = [
        't' => 'To do',
        'p' => 'Pending',
        'd' => 'Done',
    ];

    foreach ($statuses as $key => $list) {
        echo '<div class="refunds-section">';
        echo '<h3>' . esc_html($status_labels[$key]) . '</h3>';

        if (!empty($list)) {
            echo '<table class="refunds-table">';
            echo '<thead>
                    <tr>
                        <th>Customer Name</th>
                        <th>Email</th>
                        <th>Number</th>
                        <th>Service</th>
                        <th>Date</th>
                        <th>Time Slot</th>
                        <th>Length (min)</th>
                        <th>Price Main</th>
                        <th>Price Provider</th>
                        <th>Booked by Main</th>';
            if ($key === 't' || $key === 'p') {
                echo '<th>Action</th>';
            }
            echo '</tr></thead>';
            echo '<tbody>';
            foreach ($list as $refund) {
                echo '<tr>';
                echo '<td>' . esc_html($refund->customer_name) . '</td>';
                echo '<td>' . esc_html($refund->customer_email) . '</td>';
                echo '<td>' . esc_html($refund->customer_number) . '</td>';
                echo '<td>' . esc_html($refund->service_name) . '</td>';
                echo '<td>' . esc_html($refund->booking_for_date) . '</td>';
                echo '<td>' . esc_html($refund->booking_time_slot) . '</td>';
                echo '<td>' . esc_html($refund->booking_time_slot_length_min) . '</td>';
                echo '<td>' . esc_html($refund->price_main) . '</td>';
                echo '<td>' . esc_html($refund->price_provider) . '</td>';
                echo '<td>' . ($refund->booked_by_main ? 'Yes' : 'No') . '</td>';
                if ($key === 't' || $key === 'p') {
                    echo '<td><button class="status-btn" onclick="changeRefundStatus(' . $refund->refund_id . ', \'' . $refund->status . '\')">Next Status</button></td>';
                }
                echo '</tr>';
            }
            echo '</tbody></table>';
        } else {
            echo '<p>No records.</p>';
        }

        echo '</div>';
    }

    echo '</div>';
}

// REST API endpoint to update status
add_action('rest_api_init', function() {
    register_rest_route('pcp/v1', '/update_refund_status', [
        'methods' => 'POST',
        'callback' => function(WP_REST_Request $request) {
            global $wpdb;
            $refund_id = intval($request->get_param('refund_id'));
            $new_status = sanitize_text_field($request->get_param('new_status'));
            $customer_refunds = $wpdb->prefix . 'customer_refunds';

            $updated = $wpdb->update(
                $customer_refunds,
                ['status' => $new_status],
                ['refund_id' => $refund_id],
                ['%s'],
                ['%d']
            );

            if ($updated !== false) {
                return rest_ensure_response(['success' => true]);
            } else {
                return new WP_REST_Response(['success' => false, 'message' => 'Failed to update status'], 500);
            }
        },
        'permission_callback' => function($request) {
            $nonce = $request->get_header('X-WP-Nonce');
            return wp_verify_nonce($nonce, 'wp_rest') && is_user_logged_in();
        }
    ]);
});





add_action('rest_api_init', function () {
    register_rest_route('pcp/v1', '/provider/sales_history', [
        'methods' => 'POST',
        'callback' => 'provider_sales_history_rest',
        'permission_callback' => function ($request) {
            $nonce = $request->get_header('X-WP-Nonce');
            if (!wp_verify_nonce($nonce, 'wp_rest')) {
                return false;
            }
            $user = wp_get_current_user();
            return $user->exists();
        },
    ]);
});

function provider_sales_history_rest(WP_REST_Request $request) {
    global $wpdb;
    $sales_table = $wpdb->prefix . 'sale_records';
    $current_user = wp_get_current_user();
    $username = $current_user->user_login;


    $service = $request->get_param('service') ?? 'all';
    $from = $request->get_param('from') ?? null;
    $to = $request->get_param('to') ?? null;


    $query = "SELECT service_name, DATE(created_at) AS created_at, SUM(price_provider) AS total_provider_cost
              FROM {$sales_table}
              WHERE provider_name = %s";
    $params = [$username];


    if($service !== 'all') {
        $query .= " AND service_name = %s";
        $params[] = $service;
    }


    if($from) {
        $query .= " AND created_at >= %s";
        $params[] = $from;
    }
    if($to) {
        $query .= " AND created_at <= %s";
        $params[] = $to;
    }

    $query .= " GROUP BY service_name, created_at ORDER BY created_at ASC";

    $results = $wpdb->get_results($wpdb->prepare($query, ...$params));

    foreach($results as $r) {
        $r->total_provider_cost = (float)$r->total_provider_cost;
    }

    return rest_ensure_response($results);
}

add_action('rest_api_init', function () {
    register_rest_route('pcp/v1', '/provider/by_time_slot', [
        'methods' => 'POST',
        'callback' => 'by_time_slot_rest',
        'permission_callback' => function ($request) {
            $nonce = $request->get_header('X-WP-Nonce');
            if (!wp_verify_nonce($nonce, 'wp_rest')) {
                return false;
            }
            $user = wp_get_current_user();
            return $user->exists();
        },
    ]);
});

function by_time_slot_rest(WP_REST_Request $request) {
    global $wpdb;
    $sales_table = $wpdb->prefix . 'sale_records';
    $current_user = wp_get_current_user();
    $username = $current_user->user_login;

    $service = $request->get_param('service') ?? 'all';


    $query = "SELECT service_name, booking_time_slot AS start_time, booking_time_slot_length_min AS length_min, price_provider
              FROM {$sales_table}
              WHERE provider_name = %s";
    $params = [$username];

    if ($service !== 'all') {
        $query .= " AND service_name = %s";
        $params[] = $service;
    }

    $results = $wpdb->get_results($wpdb->prepare($query, ...$params));

    $slot_map = [];
    foreach ($results as $r) {
        $start = $r->start_time;
        $end_time_sec = strtotime($r->start_time) + ($r->length_min * 60);
        $end = date('H:i', $end_time_sec);
        $start = date('H:i', strtotime($r->start_time));
        $time_range = "$start - $end";

        if (!isset($slot_map[$time_range])) $slot_map[$time_range] = 0;
        $slot_map[$time_range] += (float)$r->price_provider;
    }


    $output = [];
    foreach ($slot_map as $range => $total) {
        $output[] = [
            'time_range' => $range,
            'total_provider_cost' => $total,
        ];
    }

    return rest_ensure_response($output);
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
    $service_name = null;
    if (is_array($body_data) && isset($body_data['service_name'])) {
        $service_name = sanitize_text_field($body_data['service_name']);
    }

    if (empty($service_name)) {
        return new WP_REST_Response(
            ['message' => 'service_name missing or invalid'],
            400
        );
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
    $availability_table = $wpdb->prefix . 'availability';

    $data = verify_and_decrypt_response($request);

    if ($data instanceof WP_REST_Response) {
        return $data;
    }

    $body_data = $data['body_data'];
    $provider_row = $data['provider_row'];
    $provider_id = $provider_row->provider_id;
    $service_name = null;
    if (is_array($body_data) && isset($body_data['service_name'])) {
        $service_name = sanitize_text_field($body_data['service_name']);
    }

    if (empty($service_name)) {
        return new WP_REST_Response(
            ['message' => 'service_name missing or invalid'],
            400
        );
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
        return new WP_REST_Response(['message' => 'dates_availability not found or not an array (sent incorrectly?)'], 400);
    }

    $updated_count = 0;

    foreach ($dates_availability as $entry) {
        if (!isset($entry['id'])) {
            return new WP_REST_Response(['message' => 'Missing ID'], 400);
        }
        $provider_db_id = intval($entry['id'] ?? null);
        if (!isset($entry['status'])) {
            return new WP_REST_Response(['message' => 'Missing status'], 400);
        }
        $status = sanitize_text_field($entry['status'] ?? null);

        $allowed_status = ['a', 'p', 'b'];
        if (!in_array($status, $allowed_status, true)) {
            return new WP_REST_Response(['message' => 'Invalid status'], 400);
        }

        $updated_rows = $wpdb->query(
            $wpdb->prepare("
                UPDATE $availability_table
                SET status = %s, hold_until = NULL
                WHERE provider_db_id = %d AND service_id = %d
            ", $status, $provider_db_id, $service_id)
        );

        if ($updated_rows !== false) {
            $updated_count += $updated_rows;
        }
    }

    return new WP_REST_Response(
        ['message' => "Updated $updated_count availability slot(s) successfully"],
        200
    );
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
    global $wpdb;
    $availability_table = $wpdb->prefix . 'availability';

    if ($data instanceof WP_REST_Response) {
        return $data;
    }

    $body_data = $data['body_data'];
    $provider_row = $data['provider_row'];
    $provider_id = $provider_row->provider_id;
    $service_name = null;
    if (is_array($body_data) && isset($body_data['service_name'])) {
        $service_name = sanitize_text_field($body_data['service_name']);
    }
    if (empty($service_name)) {
        return new WP_REST_Response(
            ['message' => 'service_name missing or invalid'],
            400
        );
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

add_action('rest_api_init', function () {
    register_rest_route('api/v1', '/provider_delete_service_availability_time_slot', [
        'methods' => 'DELETE',
        'callback' => 'provider_delete_service_availability_time_slot',
        'permission_callback' => '__return_true', // Custom auth will be handled in function
    ]);
});

function provider_delete_service_availability_time_slot(WP_REST_Request $request){
    $data = verify_and_decrypt_response($request);
    global $wpdb;
    $availability_table = $wpdb->prefix . 'availability';

    if ($data instanceof WP_REST_Response) {
        return $data;
    }

    $body_data = $data['body_data'];
    $provider_row = $data['provider_row'];
    $provider_id = $provider_row->provider_id;    
    $service_name = null;
    if (is_array($body_data) && isset($body_data['service_name'])) {
        $service_name = sanitize_text_field($body_data['service_name']);
    }

    if (empty($service_name)) {
        return new WP_REST_Response(
            ['message' => 'service_name missing or invalid'],
            400
        );
    }
    $time_slot = null;
    if (is_array($body_data) && isset($body_data['time_slot'])) {
        $time_slot = sanitize_text_field($body_data['time_slot']);
    }
    if (empty($time_slot)) {
        return new WP_REST_Response(
            ['message' => 'time_slot missing or invalid'],
            400
        );
    }
    $service_id = $wpdb->get_var(
        $wpdb->prepare("SELECT service_id FROM {$wpdb->prefix}services WHERE provider_id = %d AND service_name = %s", $provider_id, $service_name)
    );

    if(empty($service_id))
    {
        return new WP_REST_Response(['message' => 'No service found in DB '], 404);
    }
        
        $has_bookings = $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM $availability_table WHERE service_id = %d AND time_slot = %s AND status IN ('p','b')",
            $service_id, $time_slot
        ) );

        if ($has_bookings) {
           return new WP_REST_Response(
                ['message' => "Unable to delete time slot(s) as there are bookings"], 403
            );
        } else {            
            $deleted_rows = $wpdb->query(
                $wpdb->prepare(
                    "DELETE FROM $availability_table WHERE service_id = %d AND time_slot = %s",
                    $service_id,
                    $time_slot
                )
            );

            if ($deleted_rows === false) {
                return new WP_REST_Response(['message' => 'Database deletion failed'], 500);
            }                
            else if ($deleted_rows === 0) {
                return new WP_REST_Response(['message' => 'No matching time slot found'], 404);
            }
        } 

        return new WP_REST_Response(
            ['message' => "Deleted $deleted_rows time slot(s) successfully"], 200
        );
}

//==================
//PROVIDER ENDPOINTS
//==================

//ADD UPDATE
//Add to all udpates to db through bookings 
function main_update_services_availability($provider_info){   

    $base_api_url = $provider_info['base_api_url'];

    if ($base_api_url !== null) {
        
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
            p.provider_name,
            p.sales_email
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

    $qr_url = home_url('/ticket-verification/?reference=' . urlencode($session_id));

    foreach ($availability_rows as $row) {
        $provider = $row['provider_name'];
        $service = $row['service_name'];
        $provider_email = $row['sales_email'];

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


    $html  = '<div style="font-family: Arial, sans-serif;">';
    $html .= '<h2 style="color:#2c3e50;">Tickets</h2>';
    $html .= '<p style="font-size:14px;color:#555;"><strong>Reference number:</strong> ' . esc_html($session_id) . '</p>';

    foreach ($grouped as $provider_name => $services) {
        $html .= "<h3 style='color:#2980b9; margin-bottom:5px;'>" . esc_html($provider_name) . "</h3>";         

        foreach ($services as $service_name => $tickets) {
            $html .= "<h4 style='color:#27ae60; margin-bottom:3px;'>" . esc_html($service_name) . "</h4>";
            $html .= "<ul style='list-style-position: inside; padding-left:0; margin:10px auto; display:inline-block; text-align:left;'>";

            foreach ($tickets as $ticket) {
                $ticket_date = esc_html($ticket['available_date']);
                $ticket_from = esc_html($ticket['from_time']);
                $ticket_to   = esc_html($ticket['to_time']);

                $html .= "<li><strong>Date:</strong> {$ticket_date} | <strong>Time:</strong> {$ticket_from} - {$ticket_to}</li>";
            }

            $html .= "</ul>";
        }
        $html .= "<h4 style='color:#2980b9; margin-bottom:5px;'>" . "Provider Email: " .esc_html($provider_email) . "</h3>";
    }

    $html .= "
        <p><a href='$qr_url'>Click here</a> to view your tickets.</p>        
    ";  

        $upload_dir = wp_upload_dir();
    $logo_url = $upload_dir['baseurl'] . '/2025/09/cropped-cropped-logo-latest-transparent-bg-scaled-1.jpg';

    $html .= '<img src="' . esc_url($logo_url) . '" alt="Logo" style="max-width:200px; margin-top:20px;" />';

    $html .= "<div style='font-size:12px;color:#888;margin-top:20px;'>
            Disclaimer: There is a non-refundable admin fee included in your payment - For cancellations and refunds of activities, please contact your chosen service provider directly.
        </div>";


    $html .= '</div>';

    return $html;
}


function create_and_send_provider_emails($session_id){
    global $wpdb;


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


    foreach ($providers as $provider) {
        $provider_id = $provider->provider_id;
        $sales_email = $provider->sales_email;


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


    foreach ($availability_rows as $row) {
        $service = $row['service_name'];

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
    $html .= '<p style="font-size:14px;color:#555;"><strong>Reference number:</strong> ' . esc_html($session_id) . '</p>';

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

function update_sales_record($session_id){    
    global $wpdb;

    $availability_table = $wpdb->prefix . 'availability';
    $services_table = $wpdb->prefix . 'services';
    $provider_sites_table = $wpdb->prefix . 'provider_sites';
    $sale_records_table = $wpdb->prefix . 'sale_records';

    $sale_data_rows = $wpdb->get_results( $wpdb->prepare(
        "SELECT 
            p.provider_name,
            s.service_name,
            a.available_date,
            a.time_slot,
            a.time_slot_length_min,
            s.service_cost_main,
            s.service_cost_provider
        FROM $availability_table a
        JOIN $services_table s ON a.service_id = s.service_id
        JOIN $provider_sites_table p ON s.provider_id = p.provider_id
        WHERE a.session_id = %s",
        $session_id
    ) );

    if (!empty($sale_data_rows)) {
        foreach ($sale_data_rows as $sale_data) {
            $wpdb->insert(
                $sale_records_table,
                [
                    'provider_name'                => $sale_data->provider_name,
                    'service_name'                 => $sale_data->service_name,
                    'booking_for_date'             => $sale_data->available_date,
                    'booking_time_slot'            => $sale_data->time_slot,
                    'booking_time_slot_length_min' => $sale_data->time_slot_length_min,
                    'price_main'                   => $sale_data->service_cost_main,
                    'price_provider'               => $sale_data->service_cost_provider,
                ],
                [
                    '%s', '%s', '%s', '%s', '%d', '%f', '%f'
                ]
            );
        }
    }
}

//sends customer and provider refund email
function send_refund_email($record)
{
    $customer_email = $record->customer_email;
    $customer_name  = $record->customer_name;
    $provider_email = $record->provider_email;
    $provider_name  = $record->provider_name;
    $service_name   = $record->service_name;
}





//=========
//CRON
//=========

function pcp_release_expired_pending() {
    global $wpdb;
    $availability_table = "{$wpdb->prefix}availability";
    $bookings_table = "{$wpdb->prefix}bookings";


    $expired_rows = $wpdb->get_results("
        SELECT availability_id, session_id 
        FROM $availability_table 
        WHERE status = 'p' AND hold_until < NOW()
    ");

    $touched_sessions = [];
    $verified_sessions = [];  
    $emails_sent = [];        
    $customer_emails = []; 

    foreach ($expired_rows as $row) {
        $availability_id = $row->availability_id;
        $session_id = $row->session_id;

        $has_booking = $wpdb->get_var($wpdb->prepare("
            SELECT COUNT(*) FROM $bookings_table 
            WHERE availability_id = %d
        ", $availability_id));

        if ($has_booking && $session_id) {
            if (!isset($customer_emails[$session_id])) {
                $customer_emails[$session_id] = $wpdb->get_var($wpdb->prepare("
                    SELECT customer_email FROM $bookings_table 
                    WHERE availability_id = %d LIMIT 1
                ", $availability_id));
            }
            $customer_email = $customer_emails[$session_id];

            if (!isset($verified_sessions[$session_id])) {
                $verification_result = pcp_manual_paystack_verify($session_id);
                $verified_sessions[$session_id] = $verification_result;

                if ($verification_result['success'] && $customer_email && !isset($emails_sent[$session_id])) {
                    create_and_send_email($customer_email, $session_id);
                    $emails_sent[$session_id] = true;
                }
            } else {
                $verification_result = $verified_sessions[$session_id];
            }

            if (!$verification_result['success']) {
                if (in_array($verification_result['status'], ['abandoned', 'failed', 'cancelled'])) {
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


    $touched_sessions = array_unique($touched_sessions);

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




