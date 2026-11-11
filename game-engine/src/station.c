/*
 * House drop stations (#324 step 10): the arithmetic of clearing a batch.
 * See station.h.
 */
#include <math.h>

#include "station.h"

long long station_batch_end(long long now)
{
    return (now / STATION_BATCH_SECONDS + 1) * STATION_BATCH_SECONDS;
}

int station_buy_price(int base, long long offered, long long daily_demand)
{
    if(base <= 0)
        return 0;
    double price = base;
    if(daily_demand > 0 && offered > 0)
        price = base * pow(2.0, -(double)offered / (double)daily_demand);
    long long p = llround(price);
    return p < 1 ? 1 : (int)p;
}

long long station_share(const long long* units, const int* company, int n, long long available, long long* fill)
{
    long long asked = 0, filled = 0;

    for(int i = 0; i < n; i++)
        asked += units[i] > 0 ? units[i] : 0;

    if(available < 0)
        available = 0;

    if(asked <= available) {
        for(int i = 0; i < n; i++) {
            fill[i] = units[i] > 0 ? units[i] : 0;
            filled += fill[i];
        }
        return filled;
    }

    // Short: shares in proportion to what each asked for (rounded down)
    for(int i = 0; i < n; i++)
        fill[i] = units[i] > 0 ? (units[i] * available) / asked : 0;

    // No company more than its cap: trim its offers, in order
    long long cap = (available * STATION_COMPANY_CAP_PCT) / 100;
    if(cap < 1)
        cap = 1;
    for(int i = 0; i < n; i++) {
        long long company_total = 0;
        for(int j = 0; j < n; j++)
            if(company[j] == company[i])
                company_total += fill[j];
        if(company_total <= cap)
            continue;
        // Trim this company's offers from the last one back
        long long over = company_total - cap;
        for(int j = n - 1; j >= 0 && over > 0; j--) {
            if(company[j] != company[i])
                continue;
            long long cut = fill[j] < over ? fill[j] : over;
            fill[j] -= cut;
            over -= cut;
        }
    }

    // What rounding and the cap left over goes, in order, to offers that
    // still want more and whose company is under its cap
    for(int i = 0; i < n; i++)
        filled += fill[i];
    for(int i = 0; i < n && filled < available; i++) {
        long long company_total = 0;
        for(int j = 0; j < n; j++)
            if(company[j] == company[i])
                company_total += fill[j];
        long long want = (units[i] > 0 ? units[i] : 0) - fill[i];
        long long room = cap - company_total;
        long long give = want < room ? want : room;
        if(give > available - filled)
            give = available - filled;
        if(give > 0) {
            fill[i] += give;
            filled += give;
        }
    }
    return filled;
}
