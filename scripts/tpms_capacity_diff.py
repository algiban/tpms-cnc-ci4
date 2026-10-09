#!/usr/bin/env python3
import argparse, json

def load(path):
    with open(path, 'r', encoding='utf-8') as f:
        return json.load(f)

def as_int(v):
    try: return int(v)
    except Exception: return 0

def main():
    p=argparse.ArgumentParser(description='Bandingkan dua output tpms:capacity-snapshot --output')
    p.add_argument('before'); p.add_argument('after'); args=p.parse_args()
    a=load(args.before); b=load(args.after)
    keys=[
        'Questions','Queries','Slow_queries','Connections','Aborted_connects',
        'Created_tmp_tables','Created_tmp_disk_tables','Innodb_buffer_pool_reads',
        'Innodb_buffer_pool_read_requests','Innodb_row_lock_time','Innodb_row_lock_waits',
        'Bytes_received','Bytes_sent'
    ]
    print('TPMS Phase 8 Capacity Delta')
    print(f"before={a.get('captured_at','?')} after={b.get('captured_at','?')}")
    print(f"{'metric':38} {'before':>14} {'after':>14} {'delta':>14}")
    print('-'*84)
    for k in keys:
        av=as_int(a.get('status',{}).get(k,0)); bv=as_int(b.get('status',{}).get(k,0))
        print(f"{k:38} {av:14d} {bv:14d} {bv-av:14d}")
    size_a=as_int(a.get('database_bytes',0)); size_b=as_int(b.get('database_bytes',0))
    print('-'*84)
    print(f"{'database_bytes':38} {size_a:14d} {size_b:14d} {size_b-size_a:14d}")
    return 0

if __name__=='__main__':
    raise SystemExit(main())
