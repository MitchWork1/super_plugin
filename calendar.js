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


  let $services_info = [];
  let redirect_from_checkout = false;
  let sessionId = generateSessionId();

  continueBtn.addEventListener("click", function () {
    const name = document.getElementById("client-name").value.trim();
    const email = document.getElementById("client-email").value.trim();

    if (!name || !email) {
      alert("Please fill in both name and email.");
      return;
    }

    fetch(pcp_ajax.ajax_url, {
      method: "POST",
      headers: {
        "Content-Type": "application/x-www-form-urlencoded",
      },
      body: new URLSearchParams({
        action: "pcp_init_payment",
        security: pcp_ajax.nonce,
        session_id: sessionId,
        customer_email: email,
        customer_name: name,
      }),
    })
      .then((res) => res.json())
      .then((data) => {
        if (data.success) {
          // Redirect the user to Paystack payment page
          redirect_from_checkout = true;
          window.location.href = data.data.redirect_url;
        } else {
          console.error("Payment init failed:", data.data.message);
        }
      });
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

  window.addEventListener("beforeunload", function (e) {
    if (!redirect_from_checkout) {
      navigator.sendBeacon(
        pcp_ajax.ajax_url,
        new URLSearchParams({
          action: "pcp_release_all_spots",
          session_id: sessionId,
          security: pcp_ajax.nonce,
        })
      );
    }
  });

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
      `${
        pcp_ajax.ajax_url
      }?action=get_availability&service_id=${encodeURIComponent(
        selectedService
      )}`
    )
      .then((res) => res.json())
      .then((data) => {
        generateCalendar(currentYear, currentMonth, data);
        updateControls();
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

              const bookingSummary = document.getElementById("booking_summary");
              bookingSummary.style.display = "block";

              const serviceId = serviceSelect.value;
              const providerId = providerSelect.value;
              serviceInfo = $services_info.find(
                (s) => s.service_id == serviceId
              );
              const providerName =
                providerSelect.options[providerSelect.selectedIndex]
                  .textContent;
              const serviceName = serviceInfo.service_name;
              const cost = `R${parseFloat(serviceInfo.service_cost).toFixed(
                2
              )}`;
              const time = `${startTime} - ${endTimeStr}`;
              const date = formatDate(dateKey);

              fetch(pcp_ajax.ajax_url, {
                method: "POST",
                headers: {
                  "Content-Type": "application/x-www-form-urlencoded",
                },
                body: new URLSearchParams({
                  action: "pcp_book_spot_in_avail",
                  availability_id: selectedAvailabilityId,
                  session_id: sessionId,
                  security: pcp_ajax.nonce,
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
              <td>R${parseFloat(serviceInfo.service_cost).toFixed(2)}</td>
              <td></td>
              `;
              //tableBody.appendChild(row);

              const colum = document.createElement("td");
              const delete_button = document.createElement("button");
              delete_button.textContent = "Delete";

              delete_button.dataset.availability_id = selectedAvailabilityId;

              delete_button.addEventListener("click", (e) => {
                const spot_id = e.target.dataset.availability_id;
                const row_to_delete = e.target.closest("tr");

                const tbody = row.parentElement;
                const serviceSection = row.closest(".service-section");
                const providerSection = row.closest(".provider-section");

                fetch(pcp_ajax.ajax_url, {
                  method: "POST",
                  headers: {
                    "Content-Type": "application/x-www-form-urlencoded",
                  },
                  body: new URLSearchParams({
                    action: "pcp_release_spot",
                    availability_id: spot_id,
                    session_id: sessionId,
                    security: pcp_ajax.nonce,
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
                      totalAmountEl.textContent = `R${totalCost.toFixed(2)}`;

                      // Hide total and summary if no bookings left
                      if (allCostTds.length === 0) {
                        document.getElementById("booking_total").style.display =
                          "none";
                        document.getElementById(
                          "booking_summary"
                        ).style.display = "none";
                      }

                      // Remove empty service section
                      if (tbody.children.length === 0) {
                        serviceSection.remove();

                        // Remove provider section if no more services
                        const remainingServices =
                          providerSection.querySelectorAll(".service-section");
                        if (remainingServices.length === 0) {
                          providerSection.remove();
                        }
                      }
                      const checkoutSection =
                        document.getElementById("checkout_section");

                      if (totalCost > 0) {
                        document.getElementById("booking_total").style.display =
                          "block";
                        document.getElementById(
                          "booking_summary"
                        ).style.display = "block";
                        checkoutSection.style.display = "block";
                      } else {
                        document.getElementById("booking_total").style.display =
                          "none";
                        document.getElementById(
                          "booking_summary"
                        ).style.display = "none";
                        checkoutSection.style.display = "none";
                      }

                      updateCalendar();
                    } else {
                      alert("Error: " + data.data);
                    }
                  });
              });

              row.querySelector("td:last-child").appendChild(delete_button);
              tableBody.appendChild(row);

              const bookingTotalDiv = document.getElementById("booking_total");
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
                document.getElementById("booking_total").style.display = "none";
                document.getElementById("booking_summary").style.display =
                  "none";
                checkoutSection.style.display = "none";
              }
            });

            tr.appendChild(btn);

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

    fetch(
      `${pcp_ajax.ajax_url}?action=get_services&provider=${encodeURIComponent(
        provider
      )}`
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
            service.service_name + " - R" + service.service_cost;
          serviceSelect.appendChild(option);
        });
        if (services.length > 0) {
          serviceSelect.value = services[0].service_id;

          serviceSelect.dispatchEvent(new Event("change"));
        }
      });
  });

  serviceSelect.addEventListener("change", () => {
    const service = serviceSelect.value;
    if (!service) {
      generateCalendar(currentYear, currentMonth, {});
      return;
    }

    fetch(
      `${
        pcp_ajax.ajax_url
      }?action=get_availability&service_id=${encodeURIComponent(service)}`
    )
      .then((res) => res.json())
      .then((data) => {
        generateCalendar(currentYear, currentMonth, data);
      });
  });

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
    const response = await fetch('/wp-admin/admin-ajax.php?action=generate_session_id', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
        }
    });

    const data = await response.json();

    if (data.success) {
        console.log('Generated session ID:', data.data.session_id);
        return data.data.session_id;
    } else {
        console.error('Failed to get session ID:', data.data || data);
        return null;
    }
  }
});
