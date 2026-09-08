import httpx

url = "https://www.avanza.se/_api/market-guide/stock/5241/quote"  # 5241 = Swedbank A
headers = {"Accept": "application/json", "User-Agent": "Mozilla/5.0"}

response = httpx.get(url, headers=headers)
print(response.json())
