import pandas as pd
import json
import sys
from pathlib import Path

def convert_excel_to_json(excel_file):
    try:
        # Excelファイルを読み込む
        df = pd.read_excel(excel_file)
        
        # カラム名を英語に変換
        column_mapping = {
            '日付': 'date',
            '店舗名': 'store_name',
            '金額': 'amount',
            '支払方法': 'payment_method'
        }
        
        # カラム名を変換
        df = df.rename(columns=column_mapping)
        
        # 日付を文字列形式に変換
        df['date'] = pd.to_datetime(df['date']).dt.strftime('%Y-%m-%d')
        
        # DataFrameを辞書のリストに変換
        records = df.to_dict('records')
        
        # 出力ファイル名を生成
        output_file = Path(excel_file).with_suffix('.json')
        
        # JSONファイルに保存
        with open(output_file, 'w', encoding='utf-8') as f:
            json.dump(records, f, ensure_ascii=False, indent=4)
        
        print(f'変換が完了しました。出力ファイル: {output_file}')
        return True
        
    except Exception as e:
        print(f'エラーが発生しました: {str(e)}')
        return False

if __name__ == '__main__':
    if len(sys.argv) != 2:
        print('使用方法: python excel_to_json.py [Excelファイルのパス]')
        sys.exit(1)
        
    excel_file = sys.argv[1]
    convert_excel_to_json(excel_file)
