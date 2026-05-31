# Comparison

| Approach | Idle connection or process | Shared-hosting fit | Typical idle latency | Best use |
| --- | --- | --- | --- | --- |
| WebSocket | Persistent connection and server | Poor on basic hosting | Very low | Chat, collaboration, high-frequency updates |
| SSE | Persistent HTTP response | Often poor | Low | One-way streams where workers are available |
| Naive polling | Repeated request per tab | Available but wasteful | Configurable | Tiny integrations |
| Pusher / Ably | Managed external realtime service | Good with third party | Very low | Teams wanting hosted infrastructure |
| NoSocket | Short PHP requests, one polling tab | Good | 2-30 seconds by state | Dashboards, orders, notifications, appointments |

NoSocket does not try to beat WebSockets at latency. It aims to provide an inexpensive operational option when persistent infrastructure is unavailable or unjustified.
