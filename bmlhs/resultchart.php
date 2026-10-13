
    <canvas id="sscResultChart"></canvas>

<script src="vendor/Chart.js-2.9.4/dist/Chart.bundle.min.js"></script>

<script>
var ctx = document.getElementById('sscResultChart').getContext('2d');
var sscResultChart = new Chart(ctx, {
    type: 'bar',
    data: {
      labels: ["2020", "2019", "2018", "2017", "2016"],
      datasets: [
        {
          label: "G.P.A-5",
          backgroundColor: "#3e95cd",
          data: [15,10,25,12,5]
        },
        {
          label: "G.P.A-4.x",
          backgroundColor: "#8e5ea2",
          data: [8,11,20,5,15]
        },

        {
          label: "G.P.A-3.x",
          backgroundColor: "#3cba9f",
          data: [12,5,25,9,11]
        },

        {
          label: "G.P.A-2.x",
          backgroundColor: "#e8c3b9",
          data: [4,25,30,14,14]
        },

        {
          label: "G.P.A-1.x",
          backgroundColor: "#c45850",
          data: [3,11,14,25,3]
        },

        {
          label: "Fail",
          backgroundColor: "red",
          data: [2,7,25,11,3,14]
        }

      ]
    },
    options: {
      title: {
        display: true,
        text: 'এস.এস.সি',
        fontSize: 20,
        fontColor: 'blue',
        fontStyle: 'italic'
      },

      animation: {
      duration: 8000,
      easing: 'linear'
    }
    }
});
</script>

<canvas id="jscResultChart"></canvas>

<script src="vendor/Chart.js-2.9.4/dist/Chart.bundle.min.js"></script>

<script>
var ctx = document.getElementById('jscResultChart').getContext('2d');
var jscResultChart = new Chart(ctx, {
    type: 'bar',
    data: {
      labels: ["2020", "2019", "2018", "2017", "2016"],
      datasets: [
        {
          label: "G.P.A-5",
          backgroundColor: "#3cba9f",
          data: [5,13,35,42,10]
        },
        {
          label: "G.P.A-4.x",
          backgroundColor: "#3e95cd",
          data: [9,19,24,27,6]
        },

        {
          label: "G.P.A-3.x",
          backgroundColor: "#8e5ea2",
          data: [5,11,30,8,20]
        },

        {
          label: "G.P.A-2.x",
          backgroundColor: "#e8c3b9",
          data: [14,5,10,21,4]
        },

        {
          label: "G.P.A-1.x",
          backgroundColor: "#2C3E50",
          data: [14,24,29,15,12]
        },

        {
          label: "Fail",
          backgroundColor: "red",
          data: [13,18,10,5,11,8]
        }

      ]
    },
    options: {
        title: {
        display: true,
        text: 'জে.এস.সি',
        fontSize: 20,
        fontColor: 'blue',
        fontStyle: 'italic'
      },
      scales: {
            xAxes: [{
                stacked: true
            }],
            yAxes: [{
                stacked: true
            }]
        },
    animation: {
      duration: 6000,
      easing: 'easeInOutBounce'
    }
    }
});
</script>
