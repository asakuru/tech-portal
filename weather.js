/**
 * weather.js
 * Fetches weather from Open-Meteo API.
 * Uses Browser Geolocation first, falls back to "Home Base" PHP variables if available.
 */

const WeatherWidget = {
    init: function (fallbackLat, fallbackLng) {
        this.container = document.getElementById('weather-widget');
        if (!this.container) return;

        // Try Browser Geolocation
        if ("geolocation" in navigator) {
            navigator.geolocation.getCurrentPosition(
                (position) => {
                    this.fetchWeather(position.coords.latitude, position.coords.longitude);
                },
                (error) => {
                    console.warn("Weather: Geolocation denied/failed. Using fallback.");
                    this.useFallback(fallbackLat, fallbackLng);
                }
            );
        } else {
            this.useFallback(fallbackLat, fallbackLng);
        }
    },

    useFallback: function (lat, lng) {
        if (lat && lng && lat != 0 && lng != 0) {
            this.fetchWeather(lat, lng);
        } else {
            this.renderError("Location unavailable");
        }
    },

    fetchWeather: async function (lat, lng) {
        try {
            // Open-Meteo API (No Key Required)
            // Current weather: temp, weathercode, windspeed, precipitation probability (if available in forecast)
            const url = `https://api.open-meteo.com/v1/forecast?latitude=${lat}&longitude=${lng}&current=temperature_2m,weather_code,wind_speed_10m&daily=precipitation_probability_max&temperature_unit=fahrenheit&wind_speed_unit=mph&precipitation_unit=inch&timezone=auto&forecast_days=1`;

            this.container.innerHTML = '<div class="weather-loading">Loading weather...</div>';

            const response = await fetch(url);
            if (!response.ok) throw new Error("Weather API Error");
            const data = await response.json();

            this.render(data);

        } catch (e) {
            console.error(e);
            this.renderError("Weather unavailable");
        }
    },

    render: function (data) {
        const current = data.current;
        const daily = data.daily;

        const temp = Math.round(current.temperature_2m);
        const wind = Math.round(current.wind_speed_10m);
        const rainChance = daily.precipitation_probability_max ? daily.precipitation_probability_max[0] : 0;
        const code = current.weather_code;

        const condition = this.getCondition(code);

        // Glassmorphic Card HTML
        this.container.innerHTML = `
            <div class="weather-card">
                <div class="weather-main">
                    <div class="weather-icon">${condition.icon}</div>
                    <div class="weather-temp">${temp}°</div>
                </div>
                <div class="weather-details">
                    <div class="weather-desc">${condition.text}</div>
                    <div class="weather-stats">
                        <span title="Wind"><i class="icon-wind"></i> 💨 ${wind} mph</span>
                        <span title="Rain Chance"><i class="icon-rain"></i> ☔ ${rainChance}%</span>
                    </div>
                </div>
            </div>
        `;
    },

    renderError: function (msg) {
        this.container.innerHTML = `<div class="weather-error">${msg}</div>`;
    },

    getCondition: function (code) {
        // WMO Weather interpretation codes (http://www.wmo.int/pages/prog/www/IMOP/WMO306.html)
        // 0: Clear sky
        // 1, 2, 3: Mainly clear, partly cloudy, and overcast
        // 45, 48: Fog
        // 51, 53, 55: Drizzle
        // 61, 63, 65: Rain
        // 71, 73, 75: Snow
        // 95, 96, 99: Thunderstorm

        if (code === 0) return { icon: '☀️', text: 'Clear' };
        if (code <= 3) return { icon: '⛅', text: 'Partly Cloudy' };
        if (code <= 48) return { icon: '🌫️', text: 'Foggy' };
        if (code <= 55) return { icon: '🌦️', text: 'Drizzle' };
        if (code <= 67) return { icon: '🌧️', text: 'Rain' };
        if (code <= 77) return { icon: '❄️', text: 'Snow' };
        if (code <= 82) return { icon: '🌧️', text: 'Heavy Rain' };
        if (code <= 86) return { icon: '❄️', text: 'Snow Showers' };
        if (code <= 99) return { icon: '⛈️', text: 'Thunderstorm' };

        return { icon: '❓', text: 'Unknown' };
    }
};
