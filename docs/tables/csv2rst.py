
import sys, csv

if len(sys.argv) < 3:
    sys.stderr.write('usage:\n\t%s [-+] data.csv column1 column2 ...\n\n' % sys.argv[0])
    sys.exit(-1)

if sys.argv[1] == '-+':
    sidecross = cross = '+'
    side = vert = '|'
    allrows = True
    space = ' '
    del sys.argv[1]
else:
    cross = ' '
    vert = ' '
    sidecross = side = space = ''
    allrows = False

reader = csv.reader(file(sys.argv[1]))
header = reader.next()

cols = sys.argv[2:]
if cols == ['*']: cols = header
colsizes = []
idxmap = {}
for i, col in enumerate(cols):
    for j, field in enumerate(header):
        if col in field:
            found = True
            break
    assert j<len(header), 'could not find column "%s"' % col
    colsizes.append(len(col))
    idxmap[i] = j

data = []
for row in reader:
    data.append([])
    for i, col in enumerate(cols):
        field = row[idxmap[i]]
        colsizes[i] = max(colsizes[i], len(field))
        data[-1].append(field)

def printline(colsizes, row):
    print(side + vert.join([
            space + field + space + ' ' * (colsizes[i] - len(field))
            for i, field in enumerate(row)]) + side)

iguals1 = sidecross + cross.join(['=' * (colsize + 2*len(space)) for colsize in colsizes]) + sidecross
iguals2 = sidecross + cross.join(['-' * (colsize + 2*len(space)) for colsize in colsizes]) + sidecross

print(allrows and iguals2 or iguals1)
printline(colsizes, cols)
print(allrows and iguals2 or iguals1)
for row in data:
    printline(colsizes, row)
    if allrows: print(iguals2)
if not allrows: print(iguals1)

