document.addEventListener("DOMContentLoaded", function () {
  const providerSelect = document.getElementById("provider-select");
  const serviceSelect = document.getElementById("service-select");
  const calendarTitle = document.getElementById("calendar-title");
  const checkoutButton = document.getElementById("checkout_button");
  const prevBtn = document.getElementById("prev-month");
  const nextBtn = document.getElementById("next-month");
  const clientModal = document.getElementById("client-info-modal");
  const continueBtn = document.getElementById("submit-client-info");
  const cancelBtn = document.getElementById("cancel-client-info");
  const bookingSummary = document.getElementById("booking_summary");
  let countdownTime = 600;
  let timerInterval = null;
  const timerDisplay = document.getElementById("timer-display");
  const timer_reset_max = 3;
  let timer_reset_current= 0;
  let timerStarted = false;


  const shortWeekdays = ["Mon", "Tue", "Wed", "Thu", "Fri", "Sat", "Sun"];
  const fullWeekdays = ["Monday", "Tuesday", "Wednesday", "Thursday", "Friday", "Saturday", "Sunday"];

  serviceSelect.selectedIndex = 0;

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


  //Check if cusotmer came form callback
  const queryParams = new URLSearchParams(window.location.search);
  const reference = queryParams.get("reference");

  if(reference)
  {
    fetch(rest_object.rest_url + "verify_payment", {
        method: "POST",
        headers: {
        "Content-Type": "application/json",
        "X-WP-Nonce": rest_object.nonce,
      },
        body: JSON.stringify({ reference }),
      })
        .then((response) => response.json())
        .then((data) => {
          if (data.success) {
            alert("Booking Succesful! An email will be sent to you shortly. If not recieved within 30 minutes contact suppourt.");
            window.history.replaceState({}, document.title, window.location.pathname);
          } else {
            console.error("Verification failed:", data.error || data.message);
            
          }
        })
        .catch((error) => {
          console.error("Error contacting verification endpoint:", error);
        });
  }


  let $services_info = [];
  let redirect_from_checkout = false;
  let sessionId;
  generateSessionId();

  continueBtn.addEventListener("click", function () {
    const name = document.getElementById("client-name").value.trim();
    const email = document.getElementById("client-email").value.trim();
    const number = document.getElementById("client-number").value.trim();

    if (!name || !email || !number) {
      alert("Please fill in all fields.");
      return;
    }

    alert("You will be redirected shortly.");

    fetch(rest_object.rest_url + "init_payment", {
      method: "POST",
      headers: {
        "Content-Type": "application/json",
        "X-WP-Nonce": rest_object.nonce,
      },
      body: JSON.stringify({
        session_id: sessionId,
        customer_email: email,
        customer_name: name,
        customer_number: number,
      }),
    })
      .then((res) => res.json())
      .then((data) => {
        if (data.success) {
          // Redirect the user to Paystack payment page
          redirect_from_checkout = true;
          window.location.href = data.data.redirect_url;
        } else if (data.response && data.response.code === "invalid_email_address"){
          alert("Invalid email address");
        }else {
          console.error("Payment init failed:", data.message || data);
          alert("There was an error. Please try again.");
        }
      })
      .catch((err) => console.error("REST error:", err));
  });

  cancelBtn.addEventListener("click", function () {
    clientModal.style.display = "none"; // close modal
  });

  checkoutButton.addEventListener("click", function (e) {
    e.preventDefault();
    //Customer enter details
    clientModal.style.display = "flex";
  });

  let currentYear, currentMonth, currentDate;

  const today = new Date();
  currentYear = today.getFullYear();
  currentMonth = today.getMonth();
  currentDate = today.getDate();

function releaseSpots() {
  if (!redirect_from_checkout && sessionId) {
    const url = `${rest_object.rest_url}release_all_spots?session_id=${encodeURIComponent(sessionId)}&_wpnonce=${rest_object.nonce}`;

    if (navigator.sendBeacon) {
      navigator.sendBeacon(url);
    } else {
      fetch(url, { method: "POST", keepalive: true });
    }
  }
}

document.addEventListener("visibilitychange", function () {
  if (document.visibilityState === "hidden") {
    releaseSpots();
  }
});

window.addEventListener("pagehide", releaseSpots);


  prevBtn.addEventListener("click", () => {
    if (currentMonth === 0) {
      currentYear--;
      currentMonth = 11;
    } else {
      currentMonth--;
    }
    updateCalendar();
  });

  nextBtn.addEventListener("click", () => {
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

    fetch(
      `${rest_object.rest_url}availability?service_id=${encodeURIComponent(
        selectedService
      )}`,
      {
        method: "GET",
        headers: {
          "X-WP-Nonce": rest_object.nonce, // Optional if permission_callback requires nonce
        },
      }
    )
      .then((res) => res.json())
      .then((data) => {
        generateCalendar(currentYear, currentMonth, data);
        updateControls();
      })
      .catch((err) => {
        console.error("Error fetching availability:", err);
      });
  }

  function updateControls() {
    calendarTitle.textContent = `${new Date(
      currentYear,
      currentMonth
    ).toLocaleString("default", { month: "long", year: "numeric" })}`;

    const isCurrentMonth =
      currentYear === today.getFullYear() && currentMonth === today.getMonth();
    prevBtn.disabled = isCurrentMonth;
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

  function triggerBooking(slot, btn, startTime, endTimeStr, dateKey)
  {
              bookingSummary.style.display = "block";
              btn.disabled = true;
              btn.textContent = "Booking...";

              const all_spots_info = JSON.parse(btn.dataset.spots_info);
              /*
              let selectedAvailabilityId = null;
              for (const spot of all_spots_info) {
                if (spot.status === "a") {
                  selectedAvailabilityId = spot.availability_id;
                  break;
                }
              }            */ 

              let spotsAvailableList = [];
              for (const spot of all_spots_info) {
                if (spot.status === "a") {
                  spotsAvailableList.push(spot.availability_id);
                }
              }   
              

              const serviceId = serviceSelect.value;
              const providerId = providerSelect.value;
              serviceInfo = $services_info.find(
                (s) => s.service_id == serviceId
              );
              const providerName =
                providerSelect.options[providerSelect.selectedIndex]
                  .textContent;
              const serviceName = serviceInfo.service_name;
              const cost = `R${parseFloat(serviceInfo.service_cost_main).toFixed(
                2
              )}`;
              const time = `${startTime} - ${endTimeStr}`;
              const date = formatDate(dateKey);

              fetch(`${rest_object.rest_url}book_spot`, {
                method: "POST",
                headers: {
                  "Content-Type": "application/json",
                  "X-WP-Nonce": rest_object.nonce,
                },
                body: JSON.stringify({
                  availability_id_list: spotsAvailableList,
                  session_id: sessionId,
                }),
              })
                .then((res) => res.json())
                .then((data) => {
                  if (data.success) {
                    selectedAvailabilityId = data.availability_id;
                    btn.textContent = "Booked!";
                    btn.classList.add("booked-wave");                   

                    startTimer(false);

                    let providerSection = document.querySelector(
                      `.provider-section[data-provider-id="${providerId}"]`
                    );
                    if (!providerSection) {
                      providerSection = document.createElement("div");
                      providerSection.classList.add("provider-section");
                      providerSection.dataset.providerId = providerId;

                      const providerHeading = document.createElement("h3");
                      providerHeading.textContent = `${providerName}:`;
                      providerSection.appendChild(providerHeading);

                      bookingSummary.appendChild(providerSection);
                    }

                    let serviceSection = providerSection.querySelector(
                      `.service-section[data-service-id="${serviceId}"]`
                    );
                    if (!serviceSection) {
                      serviceSection = document.createElement("div");
                      serviceSection.classList.add("service-section");
                      serviceSection.dataset.serviceId = serviceId;

                      const heading = document.createElement("h4");
                      heading.textContent = `${serviceName}:`;
                      heading.classList.add("service-heading");
                      serviceSection.appendChild(heading);

                      const table = document.createElement("table");
                      table.classList.add("booking-table");

                      const tbody = document.createElement("tbody");
                      table.appendChild(tbody);
                      serviceSection.appendChild(table);

                      providerSection.appendChild(serviceSection);
                    }

                    const tableBody = serviceSection.querySelector("tbody");
                    const row = document.createElement("tr");
                    row.innerHTML = `
              <td>${formatDate(dateKey)}</td>
              <td>${startTime} - ${endTimeStr}</td>
              <td>R${parseFloat(serviceInfo.service_cost_main).toFixed(2)}</td>
              <td></td>
              `;
                    //tableBody.appendChild(row);

                    //const colum = document.createElement("td");
                    const delete_button = document.createElement("button");
                    delete_button.textContent = "Delete";

                    delete_button.dataset.availability_id =
                      selectedAvailabilityId;

                    delete_button.addEventListener("click", (e) => {
                      button = e.target;
                      button.disabled = true;
                      button.textContent = "Deleting...";

                      const spot_id = e.target.dataset.availability_id;
                      const row_to_delete = e.target.closest("tr");

                      const tbody = row.parentElement;
                      const serviceSection = row.closest(".service-section");
                      const providerSection = row.closest(".provider-section");

                      fetch(`${rest_object.rest_url}release_spot`, {
                        method: "POST",
                        headers: {
                          "Content-Type": "application/json",
                          "X-WP-Nonce": rest_object.nonce,
                        },
                        body: JSON.stringify({
                          availability_id: spot_id,
                          session_id: sessionId,
                        }),
                      })
                        .then((res) => res.json())
                        .then((data) => {
                          if (data.success) {
                            row_to_delete.remove();

                            // Recalculate total cost
                            const allCostTds = document.querySelectorAll(
                              "#booking_summary table.booking-table tbody td:nth-child(3)"
                            );
                            const totalCost = Array.from(allCostTds)
                              .map((td) =>
                                parseFloat(td.textContent.replace("R", ""))
                              )
                              .reduce((acc, val) => acc + val, 0);

                            // Update total display
                            const totalAmountEl =
                              document.querySelector(".total-amount");
                            totalAmountEl.textContent = `R${totalCost.toFixed(
                              2
                            )}`;

                            // Hide total and summary if no bookings left
                            if (allCostTds.length === 0) {
                              document.getElementById(
                                "booking_total"
                              ).style.display = "none";
                              document.getElementById(
                                "booking_summary"
                              ).style.display = "none";
                            }

                            // Remove empty service section
                            if (tbody.children.length === 0) {
                              serviceSection.remove();

                              // Remove provider section if no more services
                              const remainingServices =
                                providerSection.querySelectorAll(
                                  ".service-section"
                                );
                              if (remainingServices.length === 0) {
                                providerSection.remove();
                              }
                            }

                            const checkoutSection =
                              document.getElementById("checkout_section");

                            if (totalCost > 0) {
                              document.getElementById(
                                "booking_total"
                              ).style.display = "block";
                              document.getElementById(
                                "booking_summary"
                              ).style.display = "block";
                              checkoutSection.style.display = "block";
                            } else {
                              document.getElementById(
                                "booking_total"
                              ).style.display = "none";
                              document.getElementById(
                                "booking_summary"
                              ).style.display = "none";
                              checkoutSection.style.display = "none";
                            }

                            updateCalendar();
                          } else {
                            alert(
                              "Error: " + (data.message || "Unknown error")
                            );
                            button.disabled = false;
                            button.textContext = "Delete";
                          }
                        })
                        .catch((err) => {
                          console.error("REST API error:", err);
                          alert("An unexpected error occurred.");

                          button.disabled = false;
                          button.textContext = "Delete";
                        });
                    });

                    row
                      .querySelector("td:last-child")
                      .appendChild(delete_button);
                    tableBody.appendChild(row);

                    const bookingTotalDiv =
                      document.getElementById("booking_total");
                    bookingTotalDiv.style.display = "block";

                    // Get all cost cells in all booking tables
                    const allCostTds = document.querySelectorAll(
                      "#booking_summary table.booking-table tbody td:nth-child(3)"
                    );

                    // Sum all costs
                    const totalCost = Array.from(allCostTds)
                      .map((td) => parseFloat(td.textContent.replace("R", "")))
                      .reduce((acc, val) => acc + val, 0);

                    document.querySelector(
                      ".total-amount"
                    ).textContent = `R${totalCost.toFixed(2)}`;
                    const checkoutSection =
                      document.getElementById("checkout_section");

                    if (totalCost > 0) {
                      document.getElementById("booking_total").style.display =
                        "block";
                      document.getElementById("booking_summary").style.display =
                        "block";
                      checkoutSection.style.display = "block";
                    } else {
                      document.getElementById("booking_total").style.display =
                        "none";
                      document.getElementById("booking_summary").style.display =
                        "none";
                      checkoutSection.style.display = "none";
                    }
                  } else {
                    btn.textContent = "Book";
                    alert("Error: " + (data.message || "Unknown error"));
                  }
                })
                .catch((err) => {
                  console.error("Booking failed:", err);
                  btn.textContent = "Book";
                  alert("Something went wrong. Try again.");
                })
                .finally(() => {
  btn.disabled = false;
  btn.style.pointerEvents = "auto";
  btn.style.backgroundColor = "#4caf50";

  // Recalculate total cost and toggle booking summary display again
  const allCostTds = document.querySelectorAll(
    "#booking_summary table.booking-table tbody td:nth-child(3)"
  );

const totalCost = Array.from(allCostTds)
  .map((td) => parseFloat(td.textContent.replace("R", "").trim()))
  .filter((val) => !isNaN(val))
  .reduce((acc, val) => acc + val, 0);

  const bookingTotalDiv = document.getElementById("booking_total");
  const bookingSummaryDiv = document.getElementById("booking_summary");
  const checkoutSection = document.getElementById("checkout_section");

  if (totalCost > 0) {
    bookingTotalDiv.style.display = "block";
    bookingSummaryDiv.style.display = "block";
    checkoutSection.style.display = "block";
  } else {
    bookingTotalDiv.style.display = "none";
    bookingSummaryDiv.style.display = "none";
    checkoutSection.style.display = "none";
  }

  updateCalendar();
});
  }

  updateCalendar();

  providerSelect.addEventListener("change", () => {
    const provider = providerSelect.value;
    serviceSelect.innerHTML = "";
    serviceSelect.disabled = true;
    generateCalendar(currentYear, currentMonth, {}); // clear calendar

    if (!provider) {
      serviceSelect.innerHTML =
        '<option value="">-- Select Provider First --</option>';
      return;
    }

    serviceSelect.innerHTML = '<option value="">Loading services...</option>';

    fetch(
      `${rest_object.rest_url}services?provider=${encodeURIComponent(
        provider
      )}`,
      {
        method: "GET",
        headers: {
          "X-WP-Nonce": rest_object.nonce, // optional if you secure the endpoint
        },
      }
    )
      .then((res) => res.json())
      .then((services) => {
        $services_info = services;
        serviceSelect.disabled = false;
        serviceSelect.innerHTML =
          '<option value="">-- Select Service --</option>';
        services.forEach((service) => {
          const option = document.createElement("option");
          option.value = service.service_id;
          option.textContent =
            service.service_name + " - R" + service.service_cost_main;
          serviceSelect.appendChild(option);
        });
        if (services.length > 0) {
          serviceSelect.value = services[0].service_id;
          serviceSelect.dispatchEvent(new Event("change"));
        }
      })
      .catch((error) => {
        console.error("Error fetching services:", error);
        serviceSelect.innerHTML =
        '<option value="">Error loading services</option>';
      });
  });

  serviceSelect.addEventListener("change", () => {
    const service = serviceSelect.value;
    if (!service) {
      generateCalendar(currentYear, currentMonth, {});
      return;
    }

    fetch(
      `${rest_object.rest_url}availability?service_id=${encodeURIComponent(
        service
      )}`,
      {
        method: "GET",
        headers: {
          "X-WP-Nonce": rest_object.nonce, // Optional if permission_callback requires nonce
        },
      }
    )
      .then((res) => res.json())
      .then((data) => {
        generateCalendar(currentYear, currentMonth, data);
        updateControls();
      })
      .catch((err) => {
        console.error("Error fetching availability:", err);
      });
  });

function startTimer(reset = false) {

    if (timerStarted && !reset) return;

    if (reset) {
        countdownTime = 600;
    }

    timerStarted = true;
    clearInterval(timerInterval);

    timerInterval = setInterval(() => {
        countdownTime--;
        timerDisplay.textContent = formatTime(countdownTime);

        
        if (countdownTime <= 300) { 
            clearInterval(timerInterval);
            askUserStillBusy();
        }
    }, 1000);
}

function askUserStillBusy() {
    const stillBusy = confirm("Are you still completing your booking? Click OK to continue.");

    if (stillBusy) {
        if (timer_reset_current < timer_reset_max) {
            extendBookingHold(sessionId)
            timer_reset_current++;
            startTimer(true);
        } else {
            alert("Maximum waiting time reached. Please complete your booking soon.");
            window.location.reload();
        }
    } else {
        window.location.reload();
    }
}

function formatTime(seconds) {
    const mins = Math.floor(seconds / 60);
    const secs = seconds % 60;
    return `${mins.toString().padStart(2, '0')}:${secs.toString().padStart(2, '0')}`;
}

function extendBookingHold(availabilityId, extraMinutes = 5) {
    fetch(`${rest_object.rest_url}extend_hold`, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'X-WP-Nonce': rest_object.nonce,
        },
        body: JSON.stringify({
            sessionId: sessionId,
        })
    })
    .then(res => res.json())
    .then(data => {
        if (data.success) {
            console.log("Hold extended until:", data.new_hold_until);
        } else {
            console.error("Failed to extend hold:", data.message);
        }
    })
    .catch(err => console.error("REST error:", err));
}


  function formatDate(hybridDate) {
    const [yy, mm, dd] = hybridDate.split("-").map(Number);
    const date = new Date(2000 + yy, mm - 1, dd); // Add 2000 to handle years like '25' as 2025

    const monthNames = [
      "January",
      "February",
      "March",
      "April",
      "May",
      "June",
      "July",
      "August",
      "September",
      "October",
      "November",
      "December",
    ];

    const day = date.getDate();
    const suffix =
      day % 10 === 1 && day !== 11
        ? "st"
        : day % 10 === 2 && day !== 12
        ? "nd"
        : day % 10 === 3 && day !== 13
        ? "rd"
        : "th";

    return `${monthNames[date.getMonth()]} ${day}${suffix}`;
  }

  async function generateSessionId() {
    const response = await fetch(rest_object.rest_url + "generate_session_id", {
      method: "POST",
      headers: {
        "X-WP-Nonce": rest_object.nonce, // send nonce in header for security if needed
      },
    });

    const data = await response.json();

    if (data.success) {
      console.log("Generated session ID:", data.data.session_id);
      sessionId = data.data.session_id;
    } else {
      console.error("Failed to get session ID:", data.message || data);
    }
  }
});
